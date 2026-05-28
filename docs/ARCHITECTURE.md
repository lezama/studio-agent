# Studio Agent — architecture

## Why

Bring an agent into wp-admin (and into any post/page via a block) that:
- speaks the canonical WordPress 7.0 AI surface (`wp_ai_client_prompt()`, Abilities API),
- authenticates via the user's existing wpcom token (no per-site API keys),
- previews destructive changes in a Playground sandbox before applying.

## Layer boundary

```
┌────────────────────────────────────────────────────────────────────────┐
│  Studio Agent (this plugin)                                            │
│                                                                        │
│  Frontend (block)            Backend                                   │
│  ─────────────────           ──────────                                │
│  React + AgentUI             agents-api  ← canonical substrate         │
│  + slash menu                wp-ai-client ← canonical provider system  │
│  + sandbox preview           Abilities API ← canonical tool surface    │
│       │                                                                │
│       │ JSON-RPC (A2A)                                                 │
│       ▼                                                                │
│  /studio-agent/v1/agenttic/<agent>                                     │
│  ─────────────────────────────────                                     │
│  Bridge translates JSON-RPC → run_turn() → wp_ai_client_prompt()       │
│       │                                                                │
│       ▼                                                                │
│  Tool-loop with Abilities API                                          │
│  Provider: studio-wpcom (extends AbstractApiProvider)                  │
│       │                                                                │
│       ▼                                                                │
│  https://public-api.wordpress.com/wpcom/v2/ai-api-proxy                │
│       │                                                                │
│       ▼                                                                │
│  Anthropic Claude (via wpcom proxy auth)                               │
└────────────────────────────────────────────────────────────────────────┘
```

## Primitives owned by this plugin

| Primitive | Where | What it is |
|-----------|-------|------------|
| `Studio_Wpcom_Provider` | `includes/class-studio-wpcom-provider.php` | wp-ai-client provider that proxies through wpcom AI gateway |
| `Studio_Agent_Sandbox` | `includes/class-studio-agent-sandbox.php` | Session log + revision counter + tempId resolution. Candidate to promote to `agents-api` substrate. |
| `Studio_Agent_Skills` | `includes/class-studio-agent-skills.php` | Filesystem-scanned skill manifest. Loadable via abilities. |
| `Studio_Agent_Theme_Tools` | `includes/class-studio-agent-theme-tools.php` | Block-theme generator + write/activate. |
| `Studio_Agent_Snapshot` | `includes/class-studio-agent-snapshot.php` | Site → Playground blueprint, plus log replay step. |
| `Studio_Agent_Agenttic_Bridge` | `includes/class-studio-agent-agenttic-bridge.php` | JSON-RPC 2.0 / A2A wire format adapter. Mirrors openclawp's pattern. |

## Primitives reused (not owned)

- `agents-api` — agent registration, conversation contracts, channel base class
- `wp-ai-client` — provider registry, builder pattern, tool-call resolver
- Abilities API (WP 7.0 core) — `wp_register_ability`, `WP_AI_Client_Ability_Function_Resolver`
- `@automattic/agenttic-client` — `useAgentChat` hook (state, session, abort)
- `@automattic/agenttic-ui` — `<AgentUI>` rendering
- `@wp-playground/client` — embedded Playground for sandbox preview
- `@wordpress/components`, `@wordpress/element`, `@wordpress/scripts`

## Extensibility hooks

PHP filters/actions (third-party plugins can hook):

```php
// Append abilities to the agent's tool list.
add_filter( 'studio_agent_abilities', function ( $list ) {
    $list[] = 'my-plugin/my-ability';
    return $list;
} );

// Observe sandbox queue.
add_action( 'studio_agent_sandbox_op_recorded', function ( $op, $session_id ) {
    error_log( "queued op {$op['ability']} in {$session_id}" );
}, 10, 2 );

// Cache invalidation after accept.
add_action( 'studio_agent_sandbox_accepted', function ( $session_id, $temp_to_real ) {
    wp_cache_flush();
}, 10, 2 );
```

## Block composition

The `studio-agent/chat` block exposes three composable surfaces via attributes:

| Attribute | Default | Effect |
|-----------|---------|--------|
| `variant` | `full` | Preset: `full`, `chat-only`, `preview-only`. |
| `showPreview` | `true` | Render the side preview pane (sandbox or theme). |
| `showSlashMenu` | `true` | Render the `/` slash-menu overlay. |
| `defaultAgent` | `studio` | Pre-select an agent in the picker (only matters with multiple). |

Variants fan out the block into discrete user-facing primitives without forking JS bundles. Future iteration: split into separate `block.json` registrations sharing the same `view.js` entry.

## Token resolution (precedence: most secure first)

1. PHP constant `STUDIO_AGENT_WPCOM_TOKEN` — defined in `wp-config.php`.
2. Environment variable `STUDIO_AGENT_WPCOM_TOKEN` — set by Studio app at boot.
3. `wp_option studio_wpcom_token` — plaintext fallback for the bootstrap script.

Production deploys: use the constant. Never put the token in committed code or in a public REST response.

## Sandbox flow (the marquee feature)

```
                ┌────────────────────────┐
   1. Open ─────│ Studio_Agent_Sandbox   │── 2. Queue ops with tempIds
                │  (wp_options-backed)   │
                └────────────────────────┘
                         │
   3. Build blueprint ───┴─── snapshot of options + theme files +
                              recent posts + log-as-PHP steps
                         │
   4. Embed Playground ──┴─── @wp-playground/client (lazy-loaded)
                              boots WP-WASM with the blueprint
                         │
   5. User reviews ──────┴─── live render of host state + queued ops
                         │
            ┌────────────┴────────────┐
            │                         │
        Accept                     Reject
            │                         │
   6. Replay log ──── revision check, tempId resolution, replay each op
            │
   7. Real site updated, revision counter bumped
```

Limitations:
- Stale-revision detection (snapshot vs live counter); aborts on mismatch instead of merging.
- Single sandbox per site at a time.
- Snapshot scope: option subset + recent posts + active theme. No full DB dump.
- Two abilities sandbox-aware so far: `sandbox-create-post`, `sandbox-update-option`. Pattern is extensible to any ability that calls `Studio_Agent_Sandbox::record_or_pass()` first.

## Bundle strategy

| Chunk | Size | Loaded |
|-------|------|--------|
| `view.js` | ~410 KB | Initial — chat is functional with this alone. |
| Vendor (React + AgentUI) | ~2.3 MB | Initial — required for any chat UI. |
| `playground-client` (lazy) | ~558 KB | On-demand — only when the user opens a sandbox preview. |

`@wp-playground/client` is `import()`-ed inside `SandboxPreviewPane` to avoid bloating the initial chat bundle.

## Proposed PR split (for code review)

The current PoC is a single tree but should land as ~7 parallel PRs in the order below. Each PR ships independently — none blocks the next.

| PR | Scope | Files | Why this size |
|----|-------|-------|---------------|
| **1** | `studio-wpcom` provider for wp-ai-client | `class-studio-wpcom-provider.php` | Pure primitive; reusable by any consumer of `AiClient::defaultRegistry()`. |
| **2** | Studio agent registration + inspection abilities | `register-agent.php`, `register-abilities.php` (subset) | Wires `wp_register_agent('studio')` and read-only abilities (`site-info`, `list-posts`, `list-plugins`). |
| **3** | Agenttic JSON-RPC bridge | `class-studio-agent-agenttic-bridge.php` | Single-purpose adapter; mirrors openclawp's pattern. |
| **4** | Block + AgentUI + slash menu | `blocks/chat/*`, `class-studio-agent-admin.php`, `class-studio-agent-skills.php` (skill loader half) | Full chat surface; depends on PR1+PR2+PR3 at runtime but ships independently. |
| **5** | Theme tools | `class-studio-agent-theme-tools.php`, `class-studio-agent-theme-preview.php`, theme-related abilities | Self-contained feature; `preview-theme` flow stands alone. |
| **6** | Skills system (full) | `skills/*`, `class-studio-agent-skills.php`, `list-skills`/`load-skill` abilities, system-prompt auto-load | Pulls remote skills from `WordPress/agent-skills` repo. |
| **7** | Sandbox + Playground embed | `class-studio-agent-sandbox.php`, `class-studio-agent-snapshot.php`, sandbox abilities, `SandboxPreviewPane`, lazy-loaded Playground client | Largest single PR by intent; the marquee feature is hard to slice further without breaking the demo. |

PR1 is the foundation. PR2-PR6 can land in any order after PR1+PR2. PR7 needs PR4 (the block) and PR2 (the agent registration) at minimum.

## What's intentionally not in this plugin

- Provider-specific code (Anthropic SDK, OpenAI SDK). Lives in `wp-ai-client`.
- Agent runtime contracts (conversation loop, sessions, channels). Lives in `agents-api`.
- A new chat UI component library. Reuses `@automattic/agenttic-ui`.
- A new wpcom auth flow. Reuses Studio's existing wpcom token.

## What's TODO before this is shippable to wp.org

- Plugin dependency story for `agents-api` (which doesn't exist on wp.org yet).
- Real streaming SSE in the bridge (currently single-shot frame).
- Test suite (PHPUnit + Vitest).
- Promote `Studio_Agent_Sandbox` to a primitive in `agents-api` if a second consumer wants it.
- Encrypted token storage (or OAuth-style refresh) instead of plaintext options.
- Handle stale revisions with a 3-way merge instead of an abort.
