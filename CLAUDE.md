## graphify

This project has a graphify knowledge graph at graphify-out/.

Rules:
- Before answering architecture or codebase questions, read graphify-out/GRAPH_REPORT.md for god nodes and community structure
- If graphify-out/wiki/index.md exists, navigate it instead of reading raw files
- For cross-module "how does X relate to Y" questions, prefer `graphify query "<question>"`, `graphify path "<A>" "<B>"`, or `graphify explain "<concept>"` over grep — these traverse the graph's EXTRACTED + INFERRED edges instead of scanning files
- After modifying code files in this session, rebuild the graph (AST plus cached doc extraction, no API cost): `"$APPDATA/uv/tools/graphifyy/Scripts/python.exe" "$(git rev-parse --git-path hooks/graphify_rebuild.py)"`. The post-commit and post-checkout hooks run the same command. Do not run `graphify update .` in this repo: in graphifyy 0.6.2 it deletes every EXTRACTED doc-to-code edge.

Local setup notes:
- Use graphifyy 0.6.2 for every graphify step. The `graphify` command is that version (uv tool). Plain `python` has graphifyy 0.4.23, which builds different node ids, so wherever the /graphify skill runs `python -c ...`, run it with `"$APPDATA/uv/tools/graphifyy/Scripts/python.exe"` instead.
- After changing a doc that is in the graph (README.md, docs/*.md, js/README.md, tests/README.md, PLAN-*.md), run /graphify. Unchanged docs load from graphify-out/cache, so only the changed ones go to extraction agents. Have each agent write its JSON in parts of about 20 KB (single large writes stalled here), then finish with the rebuild command above. graphify-out/needs_update exists while a changed doc is waiting for extraction.
- .graphifyignore sets the scope: CHANGELOG.md, reports/, site/, tools/temporal_*, slide decks and PDFs are left out. Community names in GRAPH_REPORT.md go back to "Community N" after each rebuild.
