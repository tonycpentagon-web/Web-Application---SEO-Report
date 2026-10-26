from pathlib import Path
p=Path('docs/HANNS_SHARE_PERIOD_FIX.md');s=p.read_text(encoding='utf-8-sig');needle='## Preservation and evidence';idx=s.index(needle);s=s[:idx]+'''## Test results

The full Laravel run passed **176 tests** with one MySQL-only test skipped (3,116 assertions). After the final source-citation regression was added, the focused delivery/validation run passed **38 tests** (565 assertions), and the final period-detector run passed all six tests. The complete frontend suite passed **116 tests**, the Python suite passed **40 tests**, and TypeScript checking plus the production Vite build passed. The existing large bundle warning remains.

These results include uncertain OCR, metadata/chart/activity date exclusion, partial coverage, confirmed wrong-month/cross-month rejection, stale checks versus changed sources, employee confirmation audit, source-fingerprint invalidation, wrong-client/value conflicts, another-client isolation and immutable publication access. The separate live browser test used the running application and actual Hann's sources, not mocked public responses.

'''+s[idx:];p.write_text(s,encoding='utf-8')
