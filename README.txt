This repo is the installable/self-hosted distribution.
It can import/export data compatible with v2.2.0 dumps/csv.
Config is external via BOOKCATALOG_CONFIG.”
	•	v2.3.0 schema includes SystemInfo
	•	consumes v2.2.0 dumps/csv
	•	v2.3.2 – Added admin-only duplicate candidates report to assist with catalog cleanup and collection curation
	•	v2.3.3 – Duplicate candidate logic updated (subtitle-aware, author sort-name based). Existing duplicate reviews must be reset.
	•	maintenance: optional SQL scripts available to remove zero-width characters and normalize decomposed accents
	•	v2.6.4 – Installer params-file now auto-applies non-secret defaults; precheck accepts creatable nested target directories
