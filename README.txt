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