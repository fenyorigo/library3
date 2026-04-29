# BookCatalog v3

BookCatalog v3 is based on **v2.6.4** (schema **2.3.5**) and extends the system to support **ebooks alongside print books** in a unified catalog.

## Overview

BookCatalog is a **catalog system**, not a library manager.

- It does **not store or manage ebook files**
- It stores **metadata only**:
  - authors
  - title / subtitle
  - format (print / ebook)
  - file path (for ebooks)
  - other bibliographic data

## What’s new in v3

The main goal of v3 is to handle **print and ebook items in a single, unified model**.

Key changes:

- Introduction of **bibliographic records**
  - A single record can contain multiple copies (print and/or ebook)
- Introduction of **book copies**
  - Each format (print, epub, pdf, etc.) is stored as a separate copy
- Support for **multiple ebook formats per title**
- Addition of **language field**
- Support for **file paths** for ebook copies
- Logical delete (**soft delete**) support

### Master item concept

- The **master** is always a **print item**, if one exists
- If all print items are deleted:
  - no new master is assigned
  - ebooks are **not promoted to master**
- Covers remain attached to the bibliographic record

## Versioning

- Application version: **3.0.0-dev**
- Database schema: **3.0.0**

## Import

The import process remains compatible with v2.

Supported inputs:

- v2-style CSV exports (print books)
- v3-style CSV imports (ebooks and mixed records)

### NeoFinder ebook import

The repository includes a helper script to convert NeoFinder exports:
00-basedata/scripts/convert_ebook_inventory.php

This script converts a NeoFinder TSV export into a CSV file compatible with BookCatalog v3 import.

#### Usage

```bash
php 00-basedata/scripts/convert_ebook_inventory.php <input.tsv> [output.csv]

Input must be a tab-delimited file with columns:
Name    Path    Kind

Notes
- Multiple ebook formats of the same title are grouped into a single record
- Duplicate copies are skipped
- Language is initially set to unknown and may be inferred during import

Path handling
NeoFinder exports may use legacy macOS : path separators.
Path normalization is handled during import in v3.

Language detection
- Language detection is heuristic-based and operates on title/subtitle.
- Works best for Hungarian, English, German, and French
- May produce:
   - unknown results
   - occasional misclassification
Manual correction is expected for edge cases.

Notes
- The system prioritizes practical accuracy over complexity
- Some ambiguity (e.g. French vs German vs Hungarian overlaps) is resolved intentionally using simple heuristics
- Manual cleanup after import is part of the workflow

Status
Current state:
- Core v3 data model implemented
- Ebook import pipeline working
- Soft delete and restore implemented
- NeoFinder conversion script available
- Language heuristic implemented and validated on large datasets
This is a development version (3.0.0-dev).

