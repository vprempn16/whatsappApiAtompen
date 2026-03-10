# Testing Guidelines & Rules

## Core Principle
Our job is **ONLY** to write test cases, hit the endpoints, and find the correct structure to use them.
**IF A TEST FAILS:** We note the issue for the developer. **DO NOT** try to fix the backend code (controllers, models, etc.).

## Runner Scripts & Logging Paths

We have two main ways to run tests:

### 1. Single Phase Run
Use `run-test.ps1` to run a specific phase.
**Command:** `.\run-test.ps1 phase1` or `.\run-test.ps1 phase2`
**Log Output:** `tests/ApiTests/Phase{N}/phase{N}.log` (e.g., `tests/ApiTests/Phase2/phase2.log`)
**CSV Output:** `tests/ApiTests/Phase{N}/report.csv`

### 2. Full Suite Run
Use `run-all-tests.ps1` to run all tests sequentially from Phase 1 to the end.
**Command:** `.\run-all-tests.ps1`
**Log Output:** `storage/logs/test_suite.log`
**CSV Output:** `tests/ApiTests/reports/reports.csv`

## Important Notes
- Always clear logs at the start of the execution (handled by `TestReporter`).
- Write failures into the log/CSV exactly as they occur.
- Never manually run standalone files overriding these specific log/CSV paths. 
- Stick to the designated runners.
