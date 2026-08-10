from flask import Flask, render_template, request, redirect, url_for, session, flash
import mysql.connector
from mysql.connector import Error
from werkzeug.security import generate_password_hash, check_password_hash
from functools import wraps
import os

app = Flask(__name__)
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
