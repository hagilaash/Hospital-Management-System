# Hospital Management System (HMS)

A Hospital Management System built with **PHP and Symfony** to manage hospital operations.

The project is being developed step-by-step using a layered architecture with **Symfony, PostgreSQL, and PostgreSQL database functions**.

## Tech Stack

* PHP 8.3
* Symfony 7.4
* PostgreSQL 17
* Doctrine DBAL
* Twig
* HTML
* CSS
* JavaScript
* Nginx

## Current Features

### Login / Authentication

* Login page with username and password
* POST request handling in Symfony
* User validation against PostgreSQL
* PostgreSQL function-based login validation
* Invalid username/password handling
* Successful login handling

### Login Architecture

The login functionality follows a layered architecture:

```text
Controller
    ↓
Service
    ↓
Repository
    ↓
DAO
    ↓
DatabaseFunction
    ↓
PostgreSQL Function
    ↓
PostgreSQL Database
```

The login request flows through each layer before reaching the PostgreSQL database function.

## Project Structure

```text
src/
├── Controller/       → Handles HTTP requests
├── Service/          → Application/business logic
├── Repository/       → Repository layer
├── DAO/              → Data access layer
├── DB/               → PostgreSQL database function handling
├── Entity/           → Database entities
└── Kernel.php        → Symfony application kernel

config/               → Symfony configuration
public/               → Public entry point and assets
templates/             → Twig templates
var/                   → Symfony cache and logs
vendor/                → Composer dependencies
bin/console             → Symfony command-line tool
```

## Database

The project uses **PostgreSQL 17**.

The current login functionality uses a PostgreSQL database function:

```text
function_login()
```

The database contains a `users` table used for login validation.

## Installation

Clone the project and install the dependencies:

```bash
composer install
```

Configure the PostgreSQL database connection in:

```text
.env
```

Then clear the Symfony cache:

```bash
php bin/console cache:clear
```

Start the Symfony development server:

```bash
symfony server:start
```

## Current Development Status

### Completed

* [x] Symfony project setup
* [x] Login page
* [x] Login form
* [x] POST request handling
* [x] Login Service
* [x] Login Repository
* [x] Login DAO
* [x] DatabaseFunction layer
* [x] PostgreSQL database connection
* [x] PostgreSQL login function
* [x] Login validation
* [x] Successful and failed login handling

### Planned

* [ ] Dashboard
* [ ] Patient Management
* [ ] Doctor Management
* [ ] Appointment Management
* [ ] Pharmacy Management
* [ ] FDM Module
* [ ] OP Consultation
* [ ] Prescription Management
* [ ] Billing Module
* [ ] Reports and Analytics
* [ ] Email Notifications

## Future Enhancements

The system will gradually be extended with additional hospital management modules, reporting, notifications, billing, prescriptions, and other healthcare-related functionality.
