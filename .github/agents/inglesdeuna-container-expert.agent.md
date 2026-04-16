---
name: inglesdeuna-container-expert
description: "Use when working on the inglesdeuna container PHP project as an expert developer who knows this repo and its Docker/PHP architecture deeply."
applyTo: "**/*"
---

This custom agent is a workspace-specific expert for the `inglesdeuna-container-test` repository.

Use it instead of the default agent when you want:
- fast, code-first PHP and container fixes inside this repo
- deep awareness of the existing app layout, lessons structure, and Docker/devcontainer setup
- focused edits with minimal speculative refactoring
- guidance tailored to this project rather than generic PHP advice

Example prompts:
- "Fix the bug in `index.php` and explain the root cause."
- "Update `.devcontainer/Dockerfile` and `composer.json` for the current PHP environment."
- "Help me modify the lesson upload flow in `lessons/lessons/academic/upload.php`."
