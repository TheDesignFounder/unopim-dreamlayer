# Translation review

All 33 UnoPim locales contain 245 matching translation keys. User-facing labels,
status messages, errors, connection choices and permission names use those keys.
Host validation messages use UnoPim/Laravel's locale resources. Tool schemas and
diagnostic logs are not translated.

## Candidate review — 2026-10-22

English copy was edited for plain language. An AI-assisted language review of
all 29 non-English locale files checked the connection/consent text, chat and
history explanations, encrypted-key notice, Apply conflicts and preview status.
It also scanned every label for untranslated English, missing text, damaged
placeholders and broken translation boundaries.

The settings-section update adds 13 labels and revises six explanations across
all locales. These additions use AI-assisted draft translations with targeted
terminology checks; the previously reviewed translations are preserved.

Corrections include:

- Added the three connection/reconnect labels in every locale.
- Fixed translations of “Apply” that incorrectly referred to applying for a job
  or submitting an application. The messages now describe saving to a product.
- Replaced ambiguous “removal is promptless” wording with a clear statement that
  background removal needs no instruction.
- Completed leftover English labels in French and Russian; completed the French
  encrypted-key notice and improved French editor, status and action wording.
- Clarified the Portuguese download label and Filipino editor/account labels.

`tools/check-translations.php` verifies all keys, placeholders, non-empty values,
line boundaries and approved identical labels. It rejects whole untranslated
English labels and the previously missed English key-storage sentence.

This is an AI-assisted review, not native-speaker certification. Regional tone
and terminology can still be refined during UnoPim's language review. Do not
advertise professionally certified translations.

The excluded `tools/translate_ui.py` can explicitly refresh draft translations.
It sends only public UI labels, never credentials or customer data. Its cache
predates the reviewed corrections: do not overwrite reviewed files with cached
drafts without a new language review. Translation caches and tools are excluded
from distribution.

## Studio update — 2026-10-23

The new studio uses a separate `studio.php` dictionary. Its English copy follows
the new side chat, History drawer and product picker. Other locales receive
automatic draft translations of these new labels and the two updated history
explanations. Existing reviewed translations are preserved. These additions
need native-speaker review before claiming language certification.
