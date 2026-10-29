---
description: Generate Executive Pentest Report dari temuan sebelumnya
agent: build
---

Berdasarkan seluruh temuan dari audit sebelumnya (konteks sesi ini), hasilkan Executive Pentest Report dengan struktur:

1. **Executive Summary** — 1 halaman untuk C-level, bahasa non-teknis, overall risk score.
2. **Scope & Methodology** — OWASP ASVS Level 2, OAuth 2.0 Security BCP (RFC 9700), OIDC Core 1.0, OWASP Top 10 2021.
3. **Findings Table** — kolom: ID, Title, Severity (Critical/High/Medium/Low/Info), CVSS 3.1 Vector, Affected Component, Exploitability.
4. **Detailed Findings** — untuk setiap temuan: deskripsi, PoC, dampak, mitigasi (kode Laravel), referensi (CWE, OWASP, CVE).
5. **Attack Chains** — gabungan beberapa vuln untuk eskalasi (misal redirect_uri + XSS = full ATO).
6. **Remediation Roadmap** — prioritas (P0/P1/P2), estimasi effort, dependency.
7. **Retest Plan** — kriteria verifikasi perbaikan.

Sertakan:
- Attack tree ASCII untuk 3 temuan paling kritikal.
- Lampiran checklist OWASP ASVS (mark ✅/❌ per kontrol).
- Lampiran rekomendasi CI/CD security gate (test Pest regression, DAST nuclei, composer audit).

Format output: Markdown + tabel rapi, siap di-paste ke Confluence/Notion.