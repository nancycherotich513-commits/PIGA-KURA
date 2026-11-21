# VoteHub – Online Voting System (PHP + MySQL)

A blue, voting-themed system where:

- **Voters** register, wait for admin approval, then log in to see elections and vote.
- **Admins** log in with email and password to create elections, add positions and candidates (with photos), and approve voters.
- Elections are created with a **start and end date/time**. A start can never be earlier than the current date and time.
- Each voter can vote **once per position** per election. Votes are final.
- Voters see results only after an admin closes voting and publishes them.

---

## 1. Tools you need

| Tool | Why | Download |
|---|---|---|
| XAMPP (Apache + MySQL + PHP 8.1+) | Runs the site locally | https://www.apachefriends.org |
| VS Code | Editing the code | https://code.visualstudio.com |
| PHP Intelephense (VS Code extension) | Code completion and error checking | Search "Intelephense" in VS Code extensions |
| phpMyAdmin (bundled with XAMPP) | Imports the database | http://localhost/phpmyadmin |
| Any browser | Testing the site | – |

PHP extensions used: `pdo_mysql`, `mbstring`, `fileinfo` (all enabled by default in XAMPP).

---

## 2. Setup (Windows with XAMPP)

1. **Unzip** `voting-system.zip`. Move the `voting-system` folder into `C:\xampp\htdocs\`.
2. Open the **XAMPP Control Panel** and click **Start** for **Apache** and **MySQL**.
3. Open http://localhost/phpmyadmin, click **Import**, choose `voting-system/database.sql` and click **Go**.
   This creates the `voting_system` database, all tables, and the default admin account.
4. Copy `config.example.php` to `config.php`, then check these settings:
   - `base_url` → set to `'/voting-system'` (because the folder is inside htdocs).
   - `db` → `user` and `pass` should match your MySQL login. XAMPP default is `root` with an empty password.
   - `timezone` → `'Africa/Nairobi'` by default. Change it to your own timezone if needed (for example `'Europe/London'`).
5. Open **VS Code**: File → Open Folder → `voting-system`.
6. Open http://localhost/voting-system in your browser.

`config.php` and candidate photo uploads are local-only and are not included in the public repository. Keep the copied configuration file on your computer and upload candidate photos through the admin page.

### Alternative: run without XAMPP (PHP + MySQL installed separately)
1. Make sure MySQL is running and import `database.sql`.
2. In `config.php`, set `base_url` to `''`.
3. In a terminal inside the `voting-system` folder, run:
   ```
   php -S localhost:8000
   ```
4. Open http://localhost:8000

---

## 3. Default admin login

| Field | Value |
|---|---|
| Email | `admin@votehub.local` |
| Password | `Admin@2026` |

**Change this password before going live.** Run this in a terminal to create a new hash, then paste it into phpMyAdmin:

```
php -r "echo password_hash('YourNewPassword', PASSWORD_DEFAULT);"
```

In phpMyAdmin, run:

```sql
UPDATE voting_system.admins SET password_hash = 'PASTE_THE_HASH_HERE' WHERE email = 'admin@votehub.local';
```

To add another admin, insert a row into `admins` with a hash generated the same way.

---

## 4. How to use the system

**As admin (Admin tab on the home page):**
1. **Elections**: create election 1, 2, 3… Set the start and end date/time.
   - Today can be chosen only from the current time onwards. Any later day can start at any time.
   - The end must be after the start.
2. **Positions**: pick an election and add positions (President, Treasurer, etc.).
3. **Candidates**: choose a position, enter name and bio, and upload a photo (JPG, PNG or WEBP, max 2 MB).
4. **Voters**: approve or reject registered voters. Only approved voters can see and vote in elections.
5. **Results**: open an election for candidate counts, percentages, winners and tie flags. Close voting, then publish when ready for voters.
6. **Audit log / Data safety**: review admin actions, export CSV or print to PDF, download a JSON backup, restore a backup, or reset an upcoming test election.

**As voter (Voter login / Register tabs on the home page):**
1. Click **Register**, fill in the form and submit.
2. Wait for the admin to approve the account.
3. Log in with **Voter login**. Your elections appear after login.
4. Open a live election, select one candidate per position, review the ballot, then confirm it. A participation receipt code is shown and can be viewed later; it does not contain candidate choices.
5. The voter dashboard shows whether you have voted and offers account help if you lose access. Results appear only after the admin publishes them.

---

## 5. Project structure

```
voting-system/
├── index.php              Landing page with Voter login / Register / Admin tabs
├── config.php             Database, timezone, base URL and upload settings
├── database.sql           Database schema and default admin
├── README.md              This file
├── includes/
│   ├── bootstrap.php      Sessions, database, CSRF protection, date rules, helpers
│   └── layout.php         Header, footer and results display
├── auth/
│   ├── voter_login.php
│   ├── voter_register.php
│   ├── admin_login.php
│   └── logout.php
├── voter/
│   ├── dashboard.php      Elections list (after login only)
│   ├── election.php       Ballot, review and final confirmation
│   ├── receipt.php        Participation receipt without choices
│   └── results.php        Published results only
├── admin/
│   ├── dashboard.php      Overview and pending approvals
│   ├── elections.php      Create, open, close and reset elections
│   ├── positions.php      Manage positions
│   ├── candidates.php     Add, edit and remove candidates
│   ├── voters.php         Approve voters and export voter lists
│   ├── results.php        Tallies, turnout and publication
│   ├── export.php         CSV and print/PDF reports
│   ├── audit.php          Admin action history
│   ├── help.php           Account help requests
│   └── backup.php         JSON backup and restore
└── assets/
    ├── css/style.css      Blue theme, layout and animations
    ├── js/app.js          Confirmations, date checks, photo preview
    ├── img/vote-watermark.png   VOTE artwork (hero and watermark)
    └── uploads/candidates/      Candidate photos are stored here
```

---

## 6. Security built in

- Passwords are hashed with `password_hash()` and checked with `password_verify()`.
- All database queries use prepared statements (protection against SQL injection).
- Every form has a CSRF token.
- Output is escaped with `htmlspecialchars()` (protection against XSS).
- Sessions are regenerated at login. Cookies are HTTP-only.
- Voters and admins use separate login sessions. Voters cannot open admin pages and the reverse is also true.
- One vote per voter per position is enforced by a database UNIQUE key, not just by code.
- Ballot submission uses a transaction and a one-time confirmation token to prevent duplicate posts.
- Sessions expire after 30 minutes of inactivity. Ballots are unavailable after voting closes.
- Participation receipt codes are stored separately from candidate selections.
- Account help requests are queued for admin review; email is not sent automatically.
- Votes are checked on the server: the candidate must belong to that position and election, and voting must be open.
- Uploaded photos are checked by real MIME type, renamed randomly, and PHP execution is disabled in the uploads folder.

---

## 7. Troubleshooting

| Problem | Fix |
|---|---|
| "Database connection failed" | Start MySQL in XAMPP and check `db` settings in `config.php`. |
| Page is blank or links go to the wrong place | Check `base_url` in `config.php` matches your folder. |
| Photo upload fails | Make sure `assets/uploads/candidates` exists and is writable. Photos must be 2 MB or smaller. |
| Start time rejected | You picked a time earlier than now. Choose a later time or a future day. |
| Changing timezone | Edit `timezone` in `config.php`. |
| Reset a test election | Use **Reset test votes** while it is upcoming; votes and receipts are removed and the action is logged. |
| Want to reset all data | Import `database.sql` again in phpMyAdmin (this deletes all current data). |

---

**Before going live:** change the default admin password, make a backup, and test with test accounts. Backups are manually downloaded JSON files; store them securely outside the web root.
#   P I G A - K U R A  
 