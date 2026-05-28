# Studio Agent

A WordPress plugin that registers a Studio agent on top of [`Automattic/agents-api`](https://github.com/Automattic/agents-api) and routes prompts through the WordPress.com AI proxy via the canonical `wp-ai-client` API in WordPress 7.0+.

It's a PoC of the "WordPress is the host" approach to agentic UX: the chat lives in `wp-admin`, the provider is a registered `wp-ai-client` provider, the tools are real `wp_register_ability()` registrations.

## What's inside

- A custom `wp-ai-client` provider (`studio-wpcom`) that proxies through `https://public-api.wordpress.com/wpcom/v2/ai-api-proxy`, authenticated with the Studio user's wpcom token. No per-site API key required.
- An `agents-api` agent registration.
- Three Abilities the agent can call: `studio/site-info`, `studio/list-posts`, `studio/list-plugins`.
- A `wp-admin` chat page with a vanilla-JS UI that POSTs to a REST endpoint running a tool-calling loop (5 iterations max).
- A bootstrap script that creates the Studio site, installs the plugins, and injects the wpcom token in one command.

## Requirements

- macOS with the [Studio app](https://developer.wordpress.com/studio/) and its `studio` CLI on `PATH`.
- Logged in to WordPress.com via Studio (`studio auth status` shows `Authenticated with WordPress.com`).
- A local checkout of [`Automattic/agents-api`](https://github.com/Automattic/agents-api) as a sibling directory (or set `AGENTS_API_PATH`).

## Setup

```bash
./bin/bootstrap.sh
```

That's it. The script:

1. Creates a Studio site (`studio-agent-poc`) with WordPress 7.0 if it doesn't exist; reuses + restarts it if it does.
2. Copies `agents-api` and symlinks `studio-agent` into `wp-content/plugins/`.
3. Reads your wpcom access token from `~/.studio/shared.json` and stores it as `studio_wpcom_token` option (no token in any file under git).
4. Activates both plugins.
5. Prints the chat URL and admin credentials.

Re-run the script anytime — it's idempotent.

## Use

Open `http://localhost:<port>/wp-admin/admin.php?page=studio-agent` (the script prints the exact URL), log in as `admin` / `password`, and chat. Try:

- "What WP version is this?" → invokes `studio/site-info`.
- "List the latest posts." → invokes `studio/list-posts`.
- "Which plugins are installed?" → invokes `studio/list-plugins`.

The agent will use the tools, not make things up.

## How it talks to the model

```
wp-admin chat UI (JS fetch)
  └→ /wp-json/studio-agent/v1/chat (REST)
       └→ wp_ai_client_prompt($messages)
              ->using_system_instruction(...)
              ->using_abilities('studio/site-info', ...)
              ->generate_text_result()
            └→ provider studio-wpcom (registered with AiClient::defaultRegistry())
                 └→ POST https://public-api.wordpress.com/wpcom/v2/ai-api-proxy/v1/chat/completions
                       Authorization: Bearer <wpcom_token>
                       X-WPCOM-AI-Feature: studio-assistant-anthropic
                  → Anthropic Claude Haiku 4.5
```

If the model returns function calls, the REST handler resolves them with `WP_AI_Client_Ability_Function_Resolver`, splits each `FunctionResponse` into its own user message (OpenAI wire format requires that), and re-prompts. Up to 5 iterations.

## File map

```
studio-agent/
├── studio-agent.php                              # plugin loader
├── includes/
│   ├── class-studio-wpcom-provider.php           # Provider, Auth, Model, Directory, Availability
│   ├── class-studio-agent-rest.php               # REST handler + tool-calling loop
│   ├── class-studio-agent-conversation.php       # session storage in wp_options
│   ├── class-studio-agent-admin.php              # wp-admin menu page
│   ├── register-agent.php                        # wp_register_agent('studio')
│   └── register-abilities.php                    # 3 abilities + ability category
├── assets/
│   ├── admin-chat.js                             # vanilla JS chat client
│   └── admin-chat.css
├── bin/
│   └── bootstrap.sh                              # one-shot site setup
└── docs/screenshots/
```

## Known limitations

- WP 7.0+ only — the canonical `wp_ai_client_prompt()` path requires it. The substrate (`agents-api`) itself works on 6.5+ but the provider story is 7.0+.
- One model exposed (`claude-haiku-4-5-20251001`). Easy to widen by extending `Model_Directory`.
- Conversation history persists user/assistant text but not tool-call traces between turns. The model loses memory of which tools it called previously. Fine for a chat PoC, would need shoring up for multi-turn workflows.
- No streaming (single-shot per turn). The wpcom proxy supports SSE but the wp-ai-client integration here doesn't use it yet.
- Token in `wp_option` is plaintext. Acceptable for a local Studio site; needs encryption or transient handling for anything shared.
