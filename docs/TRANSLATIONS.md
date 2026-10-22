# Translation status

The extension provides the same translation keys for all 33 UnoPim locales. User-facing labels, status messages, errors and permission names use those keys. Host validation messages use UnoPim/Laravel's locale resources. Machine-facing tool schemas and diagnostic logs are not translated.

English copy was edited for plain language. Other languages are draft machine and AI-assisted translations of the public English labels, prepared for this candidate; they have not been reviewed by native speakers. Key and placeholder parity are checked automatically. Language review remains necessary before claiming professionally reviewed localization.

The excluded `tools/translate_ui.py` script can refresh drafts explicitly. It sends only the public UI strings to the translation endpoint. It never reads application credentials, customer messages or product data. Cached translation drafts are development material and are excluded from distributions.
