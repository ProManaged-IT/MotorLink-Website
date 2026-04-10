# Copilot Instructions for MotorLink Malawi

## 🧠 Reasoning Protocol (Senior Architect Mode)
- **Deep Loop Validation**: Before outputting code, simulate a "dry run" to check for security flaws, inefficient logic, or excessive data payloads.
- **Architectural Alignment**: Prioritize solutions that fit the existing root-level logic and module patterns (Onboarding/Admin).
- **Conciseness**: Deliver high-density code. Avoid "blabbing" or generic explanations. If it can be done in vanilla PHP/JS, do not suggest libraries.

## 📂 Project Context & Priority Paths
- **Initial Context Check**: Always reference these paths first for logic and styling:
    - **Root Core**: `api.php`, `script.js`, `proxy.php`, `config.js`.
    - **Modules**: `onboarding/` and `admin/` folders (specific HTML, JS, and PHP APIs).
    - **Global Assets**: `css/`, `js/` (especially `mobile-menu.js`), and `uploads/`.
    - **Docs**: `README.md`, `CODE_REVIEW_ANALYSIS.md`, and `CLEANUP_FINDINGS.md`.

## 🛡️ Security & Token Integrity (CRITICAL)
- **Zero-Hardcoding**: NEVER suggest hardcoded API keys, DB credentials, or SMTP passwords.
- **Live DB & SMTP Safety**: The app connects to a **live PHP database** with stored **SMTP settings**.
    - **Prepared Statements**: All live DB interactions MUST use PDO/MySQLi prepared statements.
    - **Masking**: If displaying settings in the Admin UI, always mask passwords (e.g., `********`).
- **Config Privacy**: Reference `config.example.js` for structure; never write secrets to `config.js`.
- **Environment Isolation**: Strictly respect `MODE` (UAT vs PRODUCTION) and `DEBUG` flags.

## 📱 Sleek UI/UX & Data-Minimalist Mobile
- **Design Aesthetic**: "Sublime" desktop looks with a **Native-App feel** for mobile.
- **Mobile-First Priority**: Most clients are on mobile data; keep JSON payloads lean and assets lazy-loaded.
- **App Readiness**: Ensure UI is ready for future **Android/iOS wrapping** (Capacitor/Cordova):
    - Large touch targets (min 44px).
    - No hover-dependent logic for critical actions.
    - Adapt components (menus, cards, tables) fundamentally between screen sizes.
- **Navigation**: Deep integration with `js/mobile-menu.js` for touch-optimized menus.

## ⚙️ Environment & Workflow
- **Auto-Detection**: Dynamically toggle between `proxy.php` (UAT) and `api.php` (Prod) based on hostname.
- **Permissions**: Respect `755` for core files and `777` for the `uploads/` directory.
- **Pattern Maintenance**: Follow the established flow: HTML Structure > JS Logic > PHP API > Localized CSS.

---
**Note**: Deliver secure, high-performance, and production-ready code. If a request risks security or leaks a token, pause and ask for clarification.
