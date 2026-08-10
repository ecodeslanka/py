# Flask + MySQL Login App

A simple, secure login/registration web app built with Flask and MySQL.

## Features
- User registration with hashed passwords (Werkzeug's `generate_password_hash`)
- Login with session-based authentication
- Protected dashboard route (`@login_required`)
- Flash messages for feedback (errors, success, etc.)
- Clean, responsive UI (no external CSS framework needed)

## Setup

### 1. Install dependencies
```bash
python -m venv venv
source venv/bin/activate   # On Windows: venv\Scripts\activate
pip install -r requirements.txt
```

### 2. Set up the MySQL database
Log in to MySQL and run the schema file:
```bash
mysql -u root -p < schema.sql
```
This creates a database called `login_app_db` with a `users` table.

### 3. Configure database credentials
The app reads credentials from environment variables (with fallback defaults for local dev).
Set these before running (recommended for security):

```bash
export DB_HOST=localhost
export DB_USER=root
export DB_PASSWORD=your_mysql_password
export DB_NAME=login_app_db
export SECRET_KEY=some-random-secret-string
```

On Windows (PowerShell):
```powershell
$env:DB_HOST="localhost"
$env:DB_USER="root"
$env:DB_PASSWORD="your_mysql_password"
$env:DB_NAME="login_app_db"
$env:SECRET_KEY="some-random-secret-string"
```

Alternatively, just edit the `DB_CONFIG` dictionary directly in `app.py` for quick local testing.

### 4. Run the app
```bash
python app.py
```
Visit **http://127.0.0.1:5000** in your browser.

## Project Structure
```
flask_login_app/
├── app.py                # Main Flask app (routes, DB logic, auth)
├── schema.sql             # MySQL schema (run once to set up DB)
├── requirements.txt       # Python dependencies
├── templates/
│   ├── base.html          # Shared layout/styling
│   ├── login.html         # Login form
│   ├── register.html      # Registration form
│   └── dashboard.html     # Protected page after login
```

## Security Notes
- Passwords are never stored in plain text — they're hashed with `werkzeug.security.generate_password_hash`.
- Uses parameterized SQL queries (`%s` placeholders) to prevent SQL injection.
- Set a strong, random `SECRET_KEY` in production (used to sign session cookies).
- For production deployment, run behind a WSGI server (e.g. gunicorn) and disable `debug=True`.

## Extending
- Add email verification or password reset flows
- Add role-based access control (admin/user)
- Switch to Flask-Login for more robust session management
- Use SQLAlchemy as an ORM instead of raw `mysql-connector-python` queries
