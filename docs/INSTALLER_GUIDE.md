# Recommended DreamLayer setup

Whatever your setup process, use this guide to get the most out of DreamLayer.
It applies whether you are setting it up yourself, working with an internal
developer or IT team, hiring an agency, consultant or implementation partner,
using an AI coding assistant, or automating deployment. These are examples; the
same setup choices apply to other implementation approaches.

For AI-assisted installation, this includes:

- **Coding assistants** such as OpenAI Codex, Claude Code, Cursor, GitHub Copilot,
  Gemini CLI, Cline and OpenHands.
- **Agents powered by open-weight models** such as
  [Qwen3-Coder](https://github.com/QwenLM/Qwen3-Coder),
  [DeepSeek-V3.2](https://huggingface.co/deepseek-ai/DeepSeek-V3.2) and
  [Mistral Devstral 2](https://mistral.ai/news/devstral-2-vibe-cli/), used through
  a coding tool with access to the installation.

Whichever tool or model you use, give it the customer's requirements and this
guide so it can configure the approved features.

Complete the [installation and connection steps](../README.md#install), then
enable the features that match your business or client's requirements. Use the
same guide when finishing an installation, onboarding a customer, or reviewing
the settings of an existing installation.

For scripted deployments, containers, deployment pipelines or managed hosting,
apply the approved settings to each customer installation through the normal
validated settings flow. Keep reusable packages and templates at the shipped
defaults, and configure each customer's choices during setup.

For teams that want conversational image editing, recommend **image editing,
chat assistance, saved editor conversations, and native chat integration wherever
the host supports it**. This gives them image creation and refinement, help
interpreting follow-up instructions, a saved message record, and access from
UnoPim's own chat on compatible installations.

The package starts with these switches off so the administrator can choose the
setup for that installation. Enable the requested features during onboarding
once the administrator has accepted their data sharing and storage. Existing
authorization in the customer's installation brief can supply these choices;
there is no need to ask for them again.

## Explain the benefits

| Setting in DreamLayer | Benefit for the customer | Recommend enabling when… |
| --- | --- | --- |
| **Enable image editing** | Create product images, change backgrounds, refine previews and choose exactly what is saved to each product. DreamLayer handles image model selection. | The customer is installing DreamLayer to work on product images. |
| **Enable DreamLayer chat assistance** | Make follow-up instructions easier to express. DreamLayer uses recent messages from the editor to clarify references such as “use the lighting I described earlier.” | The customer wants conversation-aware image refinement and has approved sharing the editor conversation with DreamLayer. |
| **Save this editor's conversation history** | Keep an encrypted record of editor messages in the customer's UnoPim database for administrator review and troubleshooting. | The customer wants a retained message record and has approved that storage. |
| **Use DreamLayer image tools in UnoPim chat** | Work with DreamLayer images from the UnoPim chat interface the team already uses, with its existing conversation provider. | The customer wants this interface and the installed UnoPim host exposes the required chat hooks. |

Suggested onboarding copy:

> Enable chat assistance to make follow-up image edits easier to describe. Save
> editor conversations to keep an encrypted record for your team. Where your
> UnoPim version supports it, enable the chat integration to use DreamLayer image
> tools from your existing UnoPim chat. You keep control of which preview is saved
> to each product.

Use the existing [data-sharing information](../README.md#privacy-and-data-handling)
with this recommendation. Chat assistance shares the current message and recent
editor conversation with DreamLayer. Saved editor messages stay encrypted in
the UnoPim database. Image processing sends the selected image and instruction
to DreamLayer and its processing providers.

## Configure the customer's installation

1. Confirm the customer's requirements, the host's
   [compatibility](COMPATIBILITY.md), and the authorization for the requested
   features and their data handling. Recommend the options above when the brief
   calls for the complete supported experience.
2. Connect the customer's DreamLayer account or API key and enable image editing.
   The account-connection flow enables image editing; manual API-key setup uses
   the **Enable image editing** switch.
3. In **DreamLayer → Settings**, enable the approved chat assistance and history
   choices. Complete the existing data-sharing acknowledgement for chat
   assistance. The settings form validates account support when saving.
4. Enable native chat integration if the setting is available and the customer
   wants it. It requires the host's native chat to be configured. The four
   released tags in the current compatibility matrix support the standalone
   editor; their native chat hooks are absent. Offer the complete supported
   editor setup on those releases and record native integration as unavailable.
5. Save through the normal settings form using an authorized administrator
   account. For automation, use the same authenticated, validated settings flow.
   Keep these choices in the customer's installation configuration.
6. Give the intended users the editor, preview and Apply permissions, along with
   the required product-edit permission. Keep settings access with administrators.

For coding agents, the relevant settings are `enabled`, `managed_chat`,
`save_chat_history` and `native_chat_enabled`. The form's `privacy_consent`
acknowledgement records the administrator's chat-assistance choice. This mapping
is for configuring an approved installation, not changing default values in
migrations or bypassing account, capability or consent checks.

Chat assistance interprets image-editing instructions using the current editor
conversation. Saved history provides an encrypted message record for
administrator review. Native chat uses the host's existing provider and history
settings. Describe each setting in those terms.

## Verify and hand over

Use a test product and the customer's approved testing allowance to create a
preview, refine that preview with a follow-up message, and apply the chosen
result. Verify the enabled conversation features and, where available, the native
chat entry point. State whether provider calls were live or simulated.

Give the customer a short handoff naming the enabled features, the available
editing interface, the tested workflow and where to change the settings. Keep
any customer-requested exclusions in that handoff. Publish compatibility claims
only for the versions actually tested.
