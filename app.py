from flask import Flask, render_template, request, redirect, url_for, session, flash, jsonify, abort
import mysql.connector
from mysql.connector import Error
from werkzeug.security import generate_password_hash, check_password_hash
from functools import wraps
import os
import secrets

# Templates live in ./templates when that folder exists, otherwise next to app.py
_here = os.path.dirname(os.path.abspath(__file__))
_templates = os.path.join(_here, "templates")
app = Flask(__name__, template_folder=_templates if os.path.isdir(_templates) else _here)
app.secret_key = os.environ.get("SECRET_KEY", "change-this-to-a-random-secret-key")

# ---------------------------------------------------------------------------
# Database configuration
# Set these as environment variables in production instead of hardcoding them.
# Railway's MySQL plugin auto-injects MYSQLHOST/MYSQLUSER/MYSQLPASSWORD/
# MYSQLDATABASE/MYSQLPORT into your service — those are checked first,
# falling back to the generic DB_* names (used for Aiven, local dev, etc.)
# ---------------------------------------------------------------------------
DB_CONFIG = {
    "host": os.environ.get("MYSQLHOST", os.environ.get("DB_HOST", "localhost")),
    "user": os.environ.get("MYSQLUSER", os.environ.get("DB_USER", "root")),
    "password": os.environ.get("MYSQLPASSWORD", os.environ.get("DB_PASSWORD", "")),
    "database": os.environ.get("MYSQLDATABASE", os.environ.get("DB_NAME", "login_app_db")),
    "port": int(os.environ.get("MYSQLPORT", os.environ.get("DB_PORT", 3306))),
}


def get_db_connection():
    """Create and return a new MySQL connection."""
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        return conn
    except Error as e:
        print(f"Database connection error: {e}")
        return None


def login_required(f):
    """Decorator to protect routes that require a logged-in user."""
    @wraps(f)
    def decorated_function(*args, **kwargs):
        if "user_id" not in session:
            flash("Please log in to access this page.", "warning")
            return redirect(url_for("login"))
        return f(*args, **kwargs)
    return decorated_function


# ---------------------------------------------------------------------------
# Roles & permissions
# Modules shown in the permission matrix: key -> label.
# Add a new entry here to make it appear on the Roles page.
# ---------------------------------------------------------------------------
MODULES = {
    "companies": "Companies",
    "branches": "Branches",
    "dashboard": "Dashboard",
    "reports": "Reports",
    "settings": "Settings",
}
PERMISSION_ACTIONS = ("access", "create", "edit", "delete")


def ensure_role_tables():
    """Create the roles/permissions tables (and users.role_id) if missing."""
    conn = get_db_connection()
    if conn is None:
        return
    try:
        cursor = conn.cursor()
        cursor.execute(
            """CREATE TABLE IF NOT EXISTS roles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                role_name VARCHAR(100) NOT NULL UNIQUE,
                description TEXT NULL,
                active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )"""
        )
        cursor.execute(
            """CREATE TABLE IF NOT EXISTS permissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                role_id INT NOT NULL,
                module_name VARCHAR(50) NOT NULL,
                can_access TINYINT(1) DEFAULT 0,
                can_create TINYINT(1) DEFAULT 0,
                can_edit TINYINT(1) DEFAULT 0,
                can_delete TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
                UNIQUE KEY unique_role_module (role_id, module_name)
            )"""
        )
        cursor.execute("SHOW COLUMNS FROM users LIKE 'role_id'")
        if cursor.fetchone() is None:
            cursor.execute(
                "ALTER TABLE users ADD COLUMN role_id INT NULL, "
                "ADD CONSTRAINT fk_users_role FOREIGN KEY (role_id) "
                "REFERENCES roles(id) ON DELETE SET NULL"
            )
        conn.commit()
    except Error as e:
        print(f"Could not create role tables: {e}")
    finally:
        conn.close()


def csrf_token():
    if "csrf_token" not in session:
        session["csrf_token"] = secrets.token_hex(16)
    return session["csrf_token"]


app.jinja_env.globals["csrf_token"] = csrf_token


@app.before_request
def check_csrf():
    if request.method == "POST" and request.endpoint in ("roles", "delete_role"):
        sent = request.form.get("csrf_token", "")
        if not sent or not secrets.compare_digest(sent, session.get("csrf_token", "")):
            abort(400, "Invalid CSRF token. Reload the page and try again.")


def save_permissions(cursor, role_id, form):
    """Upsert one permissions row per module from the posted checkboxes."""
    for key in MODULES:
        values = {
            action: 1 if form.get(f"permission[{key}][{action}]") else 0
            for action in PERMISSION_ACTIONS
        }
        # create/edit/delete only make sense when the module is accessible
        if not values["access"]:
            values = {action: 0 for action in PERMISSION_ACTIONS}
        cursor.execute(
            """INSERT INTO permissions
                   (role_id, module_name, can_access, can_create, can_edit, can_delete)
               VALUES (%s, %s, %s, %s, %s, %s)
               ON DUPLICATE KEY UPDATE
                   can_access = VALUES(can_access), can_create = VALUES(can_create),
                   can_edit = VALUES(can_edit), can_delete = VALUES(can_delete)""",
            (role_id, key, values["access"], values["create"], values["edit"], values["delete"]),
        )


@app.route("/roles", methods=["GET", "POST"])
@login_required
def roles():
    """User Roles & Permissions: list, create and update roles."""
    ensure_role_tables()
    conn = get_db_connection()
    if conn is None:
        flash("Database connection failed. Please try again later.", "danger")
        return redirect(url_for("dashboard"))

    try:
        cursor = conn.cursor(dictionary=True)

        if request.method == "POST":
            role_id = request.form.get("role_id", "").strip()
            role_name = request.form.get("role_name", "").strip()
            description = request.form.get("description", "").strip()
            active = 1 if request.form.get("active") else 0

            if not role_name:
                flash("Role name is required.", "danger")
            else:
                try:
                    if role_id:
                        cursor.execute(
                            "UPDATE roles SET role_name=%s, description=%s, active=%s WHERE id=%s",
                            (role_name, description, active, int(role_id)),
                        )
                        save_permissions(cursor, int(role_id), request.form)
                        flash("Role updated successfully!", "success")
                    else:
                        cursor.execute(
                            "INSERT INTO roles (role_name, description, active) VALUES (%s, %s, %s)",
                            (role_name, description, active),
                        )
                        save_permissions(cursor, cursor.lastrowid, request.form)
                        flash("Role created successfully!", "success")
                    conn.commit()
                except Error as e:
                    conn.rollback()
                    if e.errno == 1062:
                        flash(f'A role named "{role_name}" already exists.', "danger")
                    else:
                        flash(f"Error: {e}", "danger")
            return redirect(url_for("roles"))

        cursor.execute("SELECT * FROM roles ORDER BY created_at DESC, id DESC")
        role_list = cursor.fetchall()
    finally:
        conn.close()

    return render_template("roles.html", roles=role_list, modules=MODULES,
                           username=session.get("username"))


@app.route("/roles/<int:role_id>/data")
@login_required
def role_data(role_id):
    """JSON used by the Edit modal (role + its permissions)."""
    conn = get_db_connection()
    if conn is None:
        return jsonify({"error": "Database connection failed"}), 500
    try:
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT * FROM roles WHERE id=%s", (role_id,))
        role = cursor.fetchone()
        if role is None:
            return jsonify({"error": "Role not found"}), 404
        cursor.execute("SELECT * FROM permissions WHERE role_id=%s", (role_id,))
        perms = cursor.fetchall()
    finally:
        conn.close()
    for row in [role, *perms]:
        for k, v in row.items():
            if hasattr(v, "isoformat"):
                row[k] = v.isoformat()
    return jsonify({"role": role, "permissions": perms})


@app.route("/roles/<int:role_id>/delete", methods=["POST"])
@login_required
def delete_role(role_id):
    conn = get_db_connection()
    if conn is None:
        flash("Database connection failed. Please try again later.", "danger")
        return redirect(url_for("roles"))
    try:
        cursor = conn.cursor()
        cursor.execute("DELETE FROM roles WHERE id=%s", (role_id,))
        conn.commit()
        flash("Role deleted successfully!", "success")
    except Error as e:
        conn.rollback()
        flash(f"Error deleting role: {e}", "danger")
    finally:
        conn.close()
    return redirect(url_for("roles"))


@app.route("/")
def index():
    if "user_id" in session:
        return redirect(url_for("dashboard"))
    return redirect(url_for("login"))


@app.route("/register", methods=["GET", "POST"])
def register():
    if request.method == "POST":
        username = request.form.get("username", "").strip()
        email = request.form.get("email", "").strip()
        password = request.form.get("password", "")
        confirm_password = request.form.get("confirm_password", "")

        # --- Basic validation ---
        if not username or not email or not password:
            flash("All fields are required.", "danger")
            return render_template("register.html")

        if password != confirm_password:
            flash("Passwords do not match.", "danger")
            return render_template("register.html")

        if len(password) < 6:
            flash("Password must be at least 6 characters long.", "danger")
            return render_template("register.html")

        hashed_password = generate_password_hash(password)

        conn = get_db_connection()
        if conn is None:
            flash("Database connection failed. Please try again later.", "danger")
            return render_template("register.html")

        try:
            cursor = conn.cursor()

            # Check if username or email already exists
            cursor.execute(
                "SELECT id FROM users WHERE username = %s OR email = %s",
                (username, email),
            )
            if cursor.fetchone():
                flash("Username or email already exists.", "danger")
                return render_template("register.html")

            cursor.execute(
                "INSERT INTO users (username, email, password_hash) VALUES (%s, %s, %s)",
                (username, email, hashed_password),
            )
            conn.commit()
            flash("Registration successful! Please log in.", "success")
            return redirect(url_for("login"))

        except Error as e:
            conn.rollback()
            flash(f"An error occurred: {e}", "danger")
            return render_template("register.html")
        finally:
            cursor.close()
            conn.close()

    return render_template("register.html")


@app.route("/login", methods=["GET", "POST"])
def login():
    if request.method == "POST":
        username = request.form.get("username", "").strip()
        password = request.form.get("password", "")

        if not username or not password:
            flash("Please enter both username and password.", "danger")
            return render_template("login.html")

        conn = get_db_connection()
        if conn is None:
            flash("Database connection failed. Please try again later.", "danger")
            return render_template("login.html")

        try:
            cursor = conn.cursor(dictionary=True)
            cursor.execute(
                "SELECT id, username, password_hash FROM users WHERE username = %s",
                (username,),
            )
            user = cursor.fetchone()

            if user and check_password_hash(user["password_hash"], password):
                session["user_id"] = user["id"]
                session["username"] = user["username"]
                flash(f"Welcome back, {user['username']}!", "success")
                return redirect(url_for("dashboard"))
            else:
                flash("Invalid username or password.", "danger")
                return render_template("login.html")

        except Error as e:
            flash(f"An error occurred: {e}", "danger")
            return render_template("login.html")
        finally:
            cursor.close()
            conn.close()

    return render_template("login.html")


@app.route("/dashboard")
@login_required
def dashboard():
    return render_template("dashboard.html", username=session.get("username"))


@app.route("/logout")
def logout():
    session.clear()
    flash("You have been logged out.", "info")
    return redirect(url_for("login"))


if __name__ == "__main__":
    port = int(os.environ.get("PORT", 5000))
    app.run(host="0.0.0.0", port=port, debug=os.environ.get("FLASK_DEBUG", "false").lower() == "true")
