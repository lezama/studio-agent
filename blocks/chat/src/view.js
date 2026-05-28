/**
 * View entry for the studio-agent/chat block.
 *
 * Mounts <AgentUI> driven by useAgentChat from @automattic/agenttic-client,
 * talking to the studio-agent agenttic JSON-RPC bridge at
 * /wp-json/studio-agent/v1/agenttic/<agent>. Alongside the chat we render
 * a <PreviewPane>: it watches the messages stream for `studio.preview`
 * DataParts (smuggled in by the bridge) and hydrates a Playground iframe
 * with the staged theme. Accept writes the theme to the host site;
 * reject discards the transient.
 */

import { createRoot } from 'react-dom/client';
import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { Button, SelectControl } from '@wordpress/components';
import { useAgentChat } from '@automattic/agenttic-client';
import { AgentUI } from '@automattic/agenttic-ui';
import '@automattic/agenttic-ui/index.css';
import './view.css';

/**
 * Lazy import of @wp-playground/client (~700KB) — only loaded when the
 * user actually opens a sandbox preview. Keeps the initial chat bundle small.
 */
let _playgroundModule = null;
function loadPlaygroundClient() {
	if ( ! _playgroundModule ) {
		_playgroundModule = import(
			/* webpackChunkName: "playground-client" */
			'@wp-playground/client'
		);
	}
	return _playgroundModule;
}

function skillPromptFor( skill ) {
	return (
		'Load the "' +
		skill.name +
		'" skill (studio/load-skill name="' +
		skill.name +
		'") and apply its conventions for the rest of this conversation. ' +
		'Then briefly confirm what changed in your understanding.'
	);
}

/**
 * Friendly starter prompts surfaced as suggestion chips in AgentUI's empty
 * state — the first thing users see when there are no messages yet. We keep
 * them concrete and outcome-oriented so a non-developer feels invited;
 * the skill list lives behind `/` for power users.
 */
const STARTER_PROMPTS = [
	{
		id: 'starter-overview',
		label: '📊  What does this site look like right now?',
		prompt: 'Give me a quick overview of this site: WP version, active theme, recent posts, and active plugins.',
	},
	{
		id: 'starter-about',
		label: '✏️  Add an About page in a sandbox',
		prompt: 'Open a sandbox and queue an About page with one paragraph introducing the site. Then stop and tell me to review.',
	},
	{
		id: 'starter-theme',
		label: '🎨  Design me a new theme',
		prompt: 'Help me create a new block theme. Ask me a couple of style questions, then preview it in Playground before installing.',
	},
	{
		id: 'starter-skills',
		label: '⌨️  Tip: type / to see commands',
		prompt: 'List the slash commands and skills I can use here.',
	},
];

/**
 * Quick actions surfaced in the slash menu. Each maps to a natural-language
 * prompt the agent can interpret to call the right tool. We don't hit the
 * abilities directly from JS so a single chat turn captures both the user
 * intent and the tool's response in the transcript.
 */
const TOOL_QUICK_ACTIONS = [
	{
		name: 'site-info',
		description: 'Show WP version, theme, and plugin counts (studio/site-info).',
		prompt: 'Show me this site\'s key info: WP version, active theme, and installed plugins. Use the inspection tools.',
	},
	{
		name: 'list-posts',
		description: 'List the most recent posts (studio/list-posts).',
		prompt: 'List the 5 most recent posts on this site.',
	},
	{
		name: 'list-plugins',
		description: 'List installed plugins (studio/list-plugins).',
		prompt: 'List all installed plugins (active and inactive).',
	},
	{
		name: 'new-theme',
		description: 'Stage a new block theme for preview before installing.',
		prompt: 'I want to create a new theme. Ask me a couple of quick questions about style and color, then preview it.',
	},
];

const SESSION_KEY = 'studio-agent:active-session';

const PREVIEW_EVENT = 'studio-agent:preview';
const SANDBOX_EVENT = 'studio-agent:sandbox';
const TOOLS_EVENT = 'studio-agent:tools';

/**
 * Intercept fetch responses to the bridge so we can pull pending previews
 * out of the Task envelope. useAgentChat filters DataParts that don't match
 * its allowlist (toolCalls / sources / etc.), so our `kind: 'studio.preview'`
 * marker never reaches the React state. Hooking fetch lets us snoop the
 * raw response without forking the client library.
 */
function installBridgeInterceptor( bridgeUrl ) {
	if ( window.__studioAgentBridgeInstalled ) {
		return;
	}
	window.__studioAgentBridgeInstalled = true;
	const origFetch = window.fetch.bind( window );
	window.fetch = async function ( input, init ) {
		const response = await origFetch( input, init );
		try {
			const url = typeof input === 'string' ? input : input?.url;
			if ( url && url.indexOf( bridgeUrl ) === 0 ) {
				const cloned = response.clone();
				cloned
					.text()
					.then( ( raw ) => {
						// useAgentChat always uses message/stream regardless of
						// enableStreaming, so the body is SSE: a `data: <json>`
						// line followed by a blank line. Try JSON first (in
						// case the wire is plain), then fall back to SSE.
						let envelope = null;
						try {
							envelope = JSON.parse( raw );
						} catch ( _ ) {
							const match = raw.match( /^data:\s*(\{[\s\S]*\})\s*$/m );
							if ( match ) {
								try {
									envelope = JSON.parse( match[ 1 ] );
								} catch ( __ ) {}
							}
						}
						if ( ! envelope ) {
							return;
						}
						const parts = envelope?.result?.status?.message?.parts || [];
						const tools = [];
						for ( const part of parts ) {
							if ( part?.type !== 'data' ) {
								continue;
							}
							if ( part.data?.kind === 'studio.tool_calls' ) {
								if ( Array.isArray( part.data.tools ) ) {
									tools.push( ...part.data.tools );
								}
							} else if ( part.data?.kind === 'studio.preview' ) {
								const previews = part.data.previews || [];
								if ( previews.length > 0 ) {
									window.dispatchEvent(
										new CustomEvent( PREVIEW_EVENT, {
											detail: previews[ 0 ],
										} )
									);
								}
							} else if ( part.data?.kind === 'studio.sandbox' ) {
								const sandbox = part.data.sandbox;
								if ( sandbox?.session_id ) {
									window.dispatchEvent(
										new CustomEvent( SANDBOX_EVENT, {
											detail: sandbox,
										} )
									);
								}
							}
						}
						// Always emit a tools event (possibly empty) so the
						// receiver clears the previous turn's chips when the
						// next turn doesn't use any tools.
						window.dispatchEvent(
							new CustomEvent( TOOLS_EVENT, { detail: { tools } } )
						);
					} )
					.catch( () => {} );
			}
		} catch ( e ) {
			// best-effort sniffer; never break the original request.
		}
		return response;
	};
}

/**
 * Sandbox preview: render the host site's state plus the queued log inside
 * an embedded WordPress Playground via @wp-playground/client. Accept replays
 * the log against the host site; Reject discards the sandbox.
 */
function SandboxPreviewPane( { sandbox, restUrl, nonce, onAccepted, onRejected } ) {
	const iframeRef = useRef( null );
	const [ status, setStatus ] = useState( 'Initializing…' );
	const [ busy, setBusy ] = useState( false );
	const [ opCount, setOpCount ] = useState( null );
	// Bumped every time we want to force a fresh boot (e.g. when the
	// queue grew between turns).
	const [ rebootKey, setRebootKey ] = useState( 0 );

	// Watch the sandbox status for op_count changes — whenever the agent
	// queues another op, we refresh the Playground so the preview matches.
	useEffect( () => {
		if ( ! sandbox?.session_id ) {
			return;
		}
		let cancelled = false;
		const tick = async () => {
			try {
				const r = await fetch( restUrl + '/sandbox', {
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': nonce },
				} );
				if ( ! r.ok ) {
					return;
				}
				const data = await r.json();
				if ( cancelled || ! data?.active ) {
					return;
				}
				const newCount = data.session?.op_count;
				setOpCount( ( prev ) => {
					if ( prev !== null && newCount !== prev ) {
						// Trigger a re-boot when the queue size changed.
						setRebootKey( ( k ) => k + 1 );
					}
					return newCount;
				} );
			} catch ( e ) {
				/* ignore */
			}
		};
		const id = setInterval( tick, 2500 );
		tick();
		return () => {
			cancelled = true;
			clearInterval( id );
		};
	}, [ sandbox, restUrl, nonce ] );

	useEffect( () => {
		if ( ! sandbox?.session_id || ! iframeRef.current ) {
			return;
		}
		let cancelled = false;
		const totalSteps = { current: 0 };
		const completedSteps = { current: 0 };

		( async () => {
			try {
				setStatus( 'Fetching blueprint…' );
				const blueprintRes = await fetch(
					restUrl + '/sandbox/' + encodeURIComponent( sandbox.session_id ) + '/blueprint',
					{ credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } }
				);
				if ( ! blueprintRes.ok ) {
					throw new Error( 'Could not fetch blueprint (HTTP ' + blueprintRes.status + ')' );
				}
				const blueprint = await blueprintRes.json();
				if ( cancelled ) {
					return;
				}
				totalSteps.current = ( blueprint.steps || [] ).length;

				setStatus( 'Loading Playground runtime…' );
				const { startPlaygroundWeb } = await loadPlaygroundClient();
				if ( cancelled ) {
					return;
				}
				setStatus( 'Booting WordPress (≈10s)…' );
				await startPlaygroundWeb( {
					iframe: iframeRef.current,
					remoteUrl: 'https://playground.wordpress.net/remote.html',
					blueprint,
					onBlueprintStepCompleted: ( result, step ) => {
						if ( cancelled ) {
							return;
						}
						completedSteps.current += 1;
						const total = totalSteps.current || 1;
						const pct = Math.round(
							( completedSteps.current / total ) * 100
						);
						const stepName = step?.step || 'step';
						setStatus(
							`Applying ${ stepName }… (${ completedSteps.current }/${ total }, ${ pct }%)`
						);
					},
				} );
				if ( ! cancelled ) {
					setStatus( '' );
				}
			} catch ( err ) {
				if ( ! cancelled ) {
					setStatus( 'Failed to boot: ' + ( err.message || err ) );
				}
			}
		} )();
		return () => {
			cancelled = true;
		};
		// rebootKey is the live-update trigger.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ sandbox, restUrl, nonce, rebootKey ] );

	const accept = useCallback( async () => {
		setBusy( true );
		setStatus( 'Applying changes to the live site…' );
		try {
			const res = await fetch(
				restUrl + '/sandbox/' + encodeURIComponent( sandbox.session_id ) + '/accept',
				{
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
				}
			);
			const data = await res.json().catch( () => null );
			if ( ! res.ok ) {
				throw new Error( ( data && data.message ) || ( 'HTTP ' + res.status ) );
			}
			onAccepted?.( data );
		} catch ( err ) {
			setStatus( 'Accept failed: ' + ( err.message || err ) );
		} finally {
			setBusy( false );
		}
	}, [ sandbox, restUrl, nonce, onAccepted ] );

	const reject = useCallback( async () => {
		setBusy( true );
		try {
			await fetch(
				restUrl + '/sandbox/' + encodeURIComponent( sandbox.session_id ),
				{
					method: 'DELETE',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': nonce },
				}
			);
			onRejected?.();
		} catch ( err ) {
			setStatus( 'Reject failed: ' + ( err.message || err ) );
		} finally {
			setBusy( false );
		}
	}, [ sandbox, restUrl, nonce, onRejected ] );

	if ( ! sandbox ) {
		return null;
	}

	return (
		<aside className="studio-agent-preview-pane">
			<header className="studio-agent-preview-pane__header">
				<div className="studio-agent-preview-pane__title">
					<strong>Sandbox preview</strong>{ ' ' }
					<span className="studio-agent-preview-pane__meta">
						{ opCount != null ? '· ' + opCount + ' queued op' + ( opCount === 1 ? '' : 's' ) : '' }
					</span>
				</div>
				<div className="studio-agent-preview-pane__actions">
					<Button variant="primary" onClick={ accept } disabled={ busy } __next40pxDefaultSize>
						Accept all
					</Button>
					<Button variant="secondary" onClick={ reject } disabled={ busy } __next40pxDefaultSize>
						Reject
					</Button>
				</div>
			</header>
			{ status && <div className="studio-agent-preview-pane__status">{ status }</div> }
			<iframe
				ref={ iframeRef }
				className="studio-agent-preview-pane__frame"
				title="Sandbox Playground"
			/>
		</aside>
	);
}

function PreviewPane( { preview, restUrl, nonce, onAccepted, onRejected } ) {
	const [ iframeSrc, setIframeSrc ] = useState( 'about:blank' );
	const [ busy, setBusy ] = useState( false );
	const [ status, setStatus ] = useState( '' );

	useEffect( () => {
		if ( ! preview ) {
			setIframeSrc( 'about:blank' );
			setStatus( '' );
			return;
		}
		let cancelled = false;
		( async () => {
			try {
				const res = await fetch(
					restUrl + '/preview/' + encodeURIComponent( preview.preview_id ) + '/blueprint',
					{
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': nonce },
					}
				);
				if ( ! res.ok ) {
					throw new Error( 'HTTP ' + res.status );
				}
				const blueprint = await res.json();
				if ( cancelled ) {
					return;
				}
				const url =
					'https://playground.wordpress.net/?_t=' +
					encodeURIComponent( preview.preview_id ) +
					'#' +
					encodeURIComponent( JSON.stringify( blueprint ) );
				// Reset to about:blank so the iframe fully reloads when
				// the blueprint changes between turns. Hash-only changes
				// don't reboot the Playground runtime.
				setIframeSrc( 'about:blank' );
				window.requestAnimationFrame( () => {
					if ( ! cancelled ) {
						setIframeSrc( url );
					}
				} );
				setStatus( '' );
			} catch ( err ) {
				if ( ! cancelled ) {
					setStatus( 'Failed to load preview: ' + ( err.message || err ) );
				}
			}
		} )();
		return () => {
			cancelled = true;
		};
	}, [ preview, restUrl, nonce ] );

	const accept = useCallback( async () => {
		setBusy( true );
		try {
			const res = await fetch(
				restUrl + '/preview/' + encodeURIComponent( preview.preview_id ) + '/accept',
				{
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
				}
			);
			const data = await res.json().catch( () => null );
			if ( ! res.ok ) {
				throw new Error( ( data && data.message ) || ( 'HTTP ' + res.status ) );
			}
			onAccepted?.( data );
		} catch ( err ) {
			setStatus( 'Accept failed: ' + ( err.message || err ) );
		} finally {
			setBusy( false );
		}
	}, [ preview, restUrl, nonce, onAccepted ] );

	const reject = useCallback( async () => {
		setBusy( true );
		try {
			await fetch(
				restUrl + '/preview/' + encodeURIComponent( preview.preview_id ),
				{
					method: 'DELETE',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': nonce },
				}
			);
			onRejected?.();
		} catch ( err ) {
			setStatus( 'Reject failed: ' + ( err.message || err ) );
		} finally {
			setBusy( false );
		}
	}, [ preview, restUrl, nonce, onRejected ] );

	if ( ! preview ) {
		return null;
	}

	return (
		<aside className="studio-agent-preview-pane">
			<header className="studio-agent-preview-pane__header">
				<div className="studio-agent-preview-pane__title">
					<strong>Theme preview</strong>{ ' ' }
					<span className="studio-agent-preview-pane__meta">
						· { preview.theme_name } ({ preview.theme_slug })
					</span>
				</div>
				<div className="studio-agent-preview-pane__actions">
					<Button
						variant="primary"
						onClick={ accept }
						disabled={ busy }
						__next40pxDefaultSize
					>
						Accept &amp; install
					</Button>
					<Button
						variant="secondary"
						onClick={ reject }
						disabled={ busy }
						__next40pxDefaultSize
					>
						Reject
					</Button>
				</div>
			</header>
			{ status && <div className="studio-agent-preview-pane__status">{ status }</div> }
			<iframe
				className="studio-agent-preview-pane__frame"
				src={ iframeSrc }
				title="Playground preview"
				sandbox="allow-scripts allow-same-origin allow-forms allow-popups"
			/>
		</aside>
	);
}

/**
 * Slash menu: when the user types `/` at the start of the chat input we
 * pop a dropdown of available skills, filtered by whatever follows the
 * slash. Selecting one clears the textarea and submits a prompt that
 * tells the agent to load that skill.
 */
function SkillSlashMenu( { chatRef, items, onSelect } ) {
	const [ open, setOpen ] = useState( false );
	const [ query, setQuery ] = useState( '' );
	const [ focused, setFocused ] = useState( 0 );
	const [ pos, setPos ] = useState( null );

	useEffect( () => {
		if ( ! open ) {
			return;
		}
		function update() {
			const ta = chatRef.current?.querySelector( 'textarea' );
			if ( ! ta ) {
				return;
			}
			const r = ta.getBoundingClientRect();
			const menuMaxHeight = 280;
			const spaceBelow = window.innerHeight - r.bottom;
			const spaceAbove = r.top;
			// Open above the input when there's room (typical for chat
			// surfaces with a bottom-anchored composer); otherwise drop
			// below to avoid clipping at the top of the viewport.
			if ( spaceAbove >= menuMaxHeight || spaceAbove > spaceBelow ) {
				setPos( {
					mode: 'above',
					left: r.left,
					bottom: window.innerHeight - r.top + 8,
					width: r.width,
				} );
			} else {
				setPos( {
					mode: 'below',
					left: r.left,
					top: r.bottom + 8,
					width: r.width,
				} );
			}
		}
		update();
		window.addEventListener( 'resize', update );
		window.addEventListener( 'scroll', update, true );
		return () => {
			window.removeEventListener( 'resize', update );
			window.removeEventListener( 'scroll', update, true );
		};
	}, [ open, chatRef ] );

	const filtered = useMemo( () => {
		const q = query.trim().toLowerCase();
		if ( ! q ) {
			return items;
		}
		return items.filter(
			( s ) =>
				s.name.toLowerCase().includes( q ) ||
				( s.description || '' ).toLowerCase().includes( q )
		);
	}, [ items, query ] );

	useEffect( () => {
		if ( focused >= filtered.length ) {
			setFocused( 0 );
		}
	}, [ filtered, focused ] );

	useEffect( () => {
		const root = chatRef.current;
		if ( ! root ) {
			return;
		}
		// AgentUI ships its own textarea; we observe it lazily because it
		// mounts after this effect runs.
		let textarea = null;
		let cancelled = false;

		function handleInput( e ) {
			const value = e.target.value || '';
			if ( value.startsWith( '/' ) ) {
				setOpen( true );
				setQuery( value.slice( 1 ) );
			} else {
				setOpen( false );
				setQuery( '' );
			}
		}

		function handleKeyDown( e ) {
			if ( ! open ) {
				return;
			}
			if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				setFocused( ( i ) => Math.min( filtered.length - 1, i + 1 ) );
			} else if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				setFocused( ( i ) => Math.max( 0, i - 1 ) );
			} else if ( e.key === 'Enter' && filtered[ focused ] ) {
				e.preventDefault();
				e.stopPropagation();
				pickItem( filtered[ focused ] );
			} else if ( e.key === 'Escape' ) {
				e.preventDefault();
				setOpen( false );
			}
		}

		function pickItem( item ) {
			const ta = textarea;
			if ( ta ) {
				// AgentUI keeps its own textarea state; we mutate the DOM
				// value via the native setter and dispatch a real input
				// event so React picks up the change. Then a synthetic
				// keydown clears any selection and refocuses.
				const setter = Object.getOwnPropertyDescriptor(
					window.HTMLTextAreaElement.prototype,
					'value'
				).set;
				setter.call( ta, '' );
				ta.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				// Belt-and-suspenders: also clear via select-all + delete in
				// case the host component bypasses the descriptor setter.
				ta.focus();
				ta.setSelectionRange( 0, ta.value.length );
				ta.setRangeText( '', 0, ta.value.length, 'end' );
				ta.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}
			setOpen( false );
			setQuery( '' );
			onSelect( item );
		}

		function attach() {
			textarea = root.querySelector( 'textarea' );
			if ( ! textarea ) {
				return false;
			}
			textarea.addEventListener( 'input', handleInput );
			textarea.addEventListener( 'keydown', handleKeyDown, true );
			return true;
		}

		if ( ! attach() ) {
			// AgentUI hasn't mounted yet — poll briefly.
			const start = Date.now();
			const id = setInterval( () => {
				if ( cancelled || attach() || Date.now() - start > 5000 ) {
					clearInterval( id );
				}
			}, 100 );
			return () => {
				cancelled = true;
				clearInterval( id );
				if ( textarea ) {
					textarea.removeEventListener( 'input', handleInput );
					textarea.removeEventListener( 'keydown', handleKeyDown, true );
				}
			};
		}

		return () => {
			if ( textarea ) {
				textarea.removeEventListener( 'input', handleInput );
				textarea.removeEventListener( 'keydown', handleKeyDown, true );
			}
		};
	}, [ chatRef, open, filtered, focused, onSelect ] );

	if ( ! open || filtered.length === 0 || ! pos ) {
		return null;
	}

	const style = {
		position: 'fixed',
		left: pos.left + 'px',
		width: pos.width + 'px',
		...( pos.mode === 'above'
			? { bottom: pos.bottom + 'px' }
			: { top: pos.top + 'px' } ),
	};

	// Render with simple section headers when consecutive items share a kind.
	const elements = [];
	let lastKind = null;
	filtered.forEach( ( item, idx ) => {
		if ( item.kind !== lastKind ) {
			elements.push(
				<div
					key={ 'h-' + item.kind }
					className="studio-agent-slash-menu__header"
				>
					{ item.kind === 'tool' ? 'Tools' : 'Skills' }
				</div>
			);
			lastKind = item.kind;
		}
		elements.push(
			<button
				key={ item.kind + '-' + item.name }
				type="button"
				role="option"
				aria-selected={ idx === focused }
				className={
					'studio-agent-slash-menu__item' +
					( idx === focused ? ' is-focused' : '' )
				}
				onMouseEnter={ () => setFocused( idx ) }
				onMouseDown={ ( e ) => {
					// Prevent textarea from losing focus before the click fires.
					e.preventDefault();
				} }
				onClick={ () => onSelect( item ) }
			>
				<span className="studio-agent-slash-menu__name">/{ item.name }</span>
				<span className="studio-agent-slash-menu__desc">{ item.description }</span>
			</button>
		);
	} );

	return (
		<div className="studio-agent-slash-menu" role="listbox" aria-label="Slash menu" style={ style }>
			{ elements }
		</div>
	);
}

function ChatApp( { agents, defaultAgent, config } ) {
	const { bridgeUrl, restUrl, nonce } = config;
	const showPreview = config.showPreview !== false;
	const showSlashMenu = config.showSlashMenu !== false;
	const [ agentId, setAgentId ] = useState( defaultAgent || agents[ 0 ]?.slug || 'studio' );
	const [ activePreview, setActivePreview ] = useState( null );
	const [ activeSandbox, setActiveSandbox ] = useState( null );
	const [ lastTools, setLastTools ] = useState( [] );
	const [ skills, setSkills ] = useState( [] );
	const chatSectionRef = useRef( null );

	useEffect( () => {
		installBridgeInterceptor( bridgeUrl );
		const onPreview = ( e ) => setActivePreview( e.detail );
		const onSandbox = ( e ) => setActiveSandbox( e.detail );
		const onTools = ( e ) => setLastTools( e.detail?.tools || [] );
		window.addEventListener( PREVIEW_EVENT, onPreview );
		window.addEventListener( SANDBOX_EVENT, onSandbox );
		window.addEventListener( TOOLS_EVENT, onTools );
		return () => {
			window.removeEventListener( PREVIEW_EVENT, onPreview );
			window.removeEventListener( SANDBOX_EVENT, onSandbox );
			window.removeEventListener( TOOLS_EVENT, onTools );
		};
	}, [ bridgeUrl ] );

	// Keyboard shortcuts: ⌘/Ctrl+K focuses the chat input; ⌘/Ctrl+/ jumps
	// straight into the slash menu by inserting "/".
	useEffect( () => {
		function onKey( e ) {
			const mod = e.metaKey || e.ctrlKey;
			if ( ! mod ) {
				return;
			}
			const key = e.key.toLowerCase();
			if ( key !== 'k' && key !== '/' ) {
				return;
			}
			const ta = chatSectionRef.current?.querySelector( 'textarea' );
			if ( ! ta ) {
				return;
			}
			e.preventDefault();
			ta.focus();
			if ( key === '/' ) {
				const setter = Object.getOwnPropertyDescriptor(
					window.HTMLTextAreaElement.prototype,
					'value'
				).set;
				setter.call( ta, '/' );
				ta.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}
		}
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [] );

	// On mount, ask the server if there's already an active sandbox so we
	// recover the right pane after a page reload.
	useEffect( () => {
		fetch( restUrl + '/sandbox', {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': nonce },
		} )
			.then( ( r ) => r.json() )
			.then( ( data ) => {
				if ( data?.active && data.session?.id ) {
					setActiveSandbox( { session_id: data.session.id } );
				}
			} )
			.catch( () => {} );
	}, [ restUrl, nonce ] );

	// Fetch the skill manifest once so we can drive the slash menu and
	// register them as empty-state suggestions.
	useEffect( () => {
		let cancelled = false;
		fetch( restUrl + '/skills', {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': nonce },
		} )
			.then( ( r ) => r.json() )
			.then( ( data ) => {
				if ( ! cancelled && Array.isArray( data?.skills ) ) {
					setSkills( data.skills );
				}
			} )
			.catch( () => {} );
		return () => {
			cancelled = true;
		};
	}, [ restUrl, nonce ] );

	const authProvider = useCallback(
		async () => ( { 'X-WP-Nonce': nonce } ),
		[ nonce ]
	);
	const sessionIdStorageKey = useMemo(
		() => SESSION_KEY + ':' + agentId,
		[ agentId ]
	);

	const {
		messages,
		isProcessing,
		error,
		onSubmit,
		abortCurrentRequest,
		suggestions,
		clearSuggestions,
		registerSuggestions,
	} = useAgentChat( {
		agentId,
		agentUrl: bridgeUrl,
		sessionId: '',
		sessionIdStorageKey,
		authProvider: nonce ? authProvider : undefined,
		credentials: 'same-origin',
		// The bridge runs the chat synchronously and emits a single SSE
		// frame; PHP-WASM (Studio's Playground runtime) closes the request
		// abruptly after that, which the streaming reader sees as
		// `net::ERR_ABORTED`. Use plain JSON-RPC `message/send` until the
		// bridge does real token-by-token streaming.
		enableStreaming: false,
	} );

	// Register friendly starter prompts as empty-state suggestions. The
	// full skill catalogue stays behind `/` to avoid overwhelming
	// non-technical users on first visit.
	useEffect( () => {
		if ( ! registerSuggestions ) {
			return;
		}
		registerSuggestions( STARTER_PROMPTS );
	}, [ registerSuggestions ] );

	const slashItems = useMemo( () => {
		const tools = TOOL_QUICK_ACTIONS.map( ( t ) => ( {
			kind: 'tool',
			name: t.name,
			description: t.description,
			prompt: t.prompt,
		} ) );
		const skillItems = skills
			// `studio` is auto-loaded into the system prompt; keep it in /skills
			// for completeness but don't bother re-loading it on demand.
			.filter( ( s ) => s.name !== 'studio' )
			.map( ( s ) => ( {
				kind: 'skill',
				name: s.name,
				description: s.description,
				prompt: skillPromptFor( s ),
			} ) );
		return [ ...tools, ...skillItems ];
	}, [ skills ] );

	const onSelectSlashItem = useCallback(
		( item ) => {
			onSubmit( item.prompt );
		},
		[ onSubmit ]
	);

	const showSidePane = showPreview && ( activeSandbox || activePreview );

	return (
		<div
			className={
				'studio-agent-layout' +
				( showSidePane ? ' studio-agent-layout--with-preview' : '' )
			}
		>
			<section className="studio-agent-chat" ref={ chatSectionRef }>
				{ showSlashMenu && (
					<SkillSlashMenu
						chatRef={ chatSectionRef }
						items={ slashItems }
						onSelect={ onSelectSlashItem }
					/>
				) }
				{ agents.length > 1 && (
					<SelectControl
						label="Agent"
						value={ agentId }
						options={ agents.map( ( a ) => ( {
							label: a.label,
							value: a.slug,
						} ) ) }
						onChange={ setAgentId }
						__next40pxDefaultSize
					/>
				) }
				<AgentUI
					variant="embedded"
					messages={ messages }
					isProcessing={ isProcessing }
					error={ error }
					onSubmit={ onSubmit }
					onStop={ abortCurrentRequest }
					suggestions={ suggestions }
					clearSuggestions={ clearSuggestions }
					placeholder="Type a message…"
				/>
				{ lastTools.length > 0 && (
					<div className="studio-agent-tools-strip" aria-label="Tools used in last turn">
						<span className="studio-agent-tools-strip__label">Used:</span>
						{ lastTools.map( ( t, i ) => (
							<code key={ i } className="studio-agent-tools-strip__chip">
								{ t.name }
							</code>
						) ) }
					</div>
				) }
			</section>
			{ showPreview && ( activeSandbox ? (
				<SandboxPreviewPane
					sandbox={ activeSandbox }
					restUrl={ restUrl }
					nonce={ nonce }
					onAccepted={ () => setActiveSandbox( null ) }
					onRejected={ () => setActiveSandbox( null ) }
				/>
			) : (
				<PreviewPane
					preview={ activePreview }
					restUrl={ restUrl }
					nonce={ nonce }
					onAccepted={ () => setActivePreview( null ) }
					onRejected={ () => setActivePreview( null ) }
				/>
			) ) }
		</div>
	);
}

function mount() {
	const root = document.getElementById( 'studio-agent-chat-root' );
	if ( ! root ) {
		return;
	}

	let agents = [];
	try {
		agents = JSON.parse( root.dataset.agents || '[]' );
	} catch ( e ) {
		agents = [];
	}
	let config = {};
	try {
		config = JSON.parse( root.dataset.config || '{}' );
	} catch ( e ) {
		config = {};
	}
	const defaultAgent = root.dataset.defaultAgent || '';

	if ( agents.length === 0 ) {
		return;
	}

	createRoot( root ).render(
		<ChatApp
			agents={ agents }
			defaultAgent={ defaultAgent }
			config={ config }
		/>
	);
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
