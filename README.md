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

## Architecture

The application follows a layered architecture:

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

Each module follows the same layered approach to separate HTTP handling, application logic, data access, and database operations.

---

# Current Features

## Login / Authentication

* Login page with username and password
* POST request handling in Symfony
* User validation against PostgreSQL
* PostgreSQL function-based login validation
* Invalid username/password handling
* Successful login handling

### Login Flow

```text
LoginController
      ↓
LoginService
      ↓
LoginRepository
      ↓
LoginDAO
      ↓
DatabaseFunction
      ↓
function_login()
      ↓
PostgreSQL
```

---

## Home Dashboard

After successful login, the user is redirected to the Home Dashboard.

The dashboard loads available hospital modules from PostgreSQL.

### Current Dashboard Modules

* FDM
* OP Consultation
* Doctors
* Patients
* Pharmacy

### Home Flow

```text
HomeController
      ↓
HomeService
      ↓
HomeRepository
      ↓
HomeDAO
      ↓
DatabaseFunction
      ↓
function_get_home_modules()
      ↓
PostgreSQL
```

The dashboard currently displays the modules returned by the PostgreSQL function.

The **Doctors** module is connected to the Doctor Management module.

---

## Doctor Management

The Doctor Management module retrieves doctor information from PostgreSQL.

### Doctor Database Table

```text
ecs_doctors
```

The table contains information such as:

* Doctor ID
* Doctor Name
* Specialization
* Department
* Phone
* Email
* Active Status

### PostgreSQL Function

The Doctor module uses:

```text
ecs_get_doctors_list()
```

The function retrieves the active doctors from the `ecs_doctors` table.

### Doctor Flow

```text
DoctorController
      ↓
DoctorService
      ↓
DoctorRepository
      ↓
DoctorDAO
      ↓
DatabaseFunction
      ↓
ecs_get_doctors_list()
      ↓
ecs_doctors
      ↓
PostgreSQL
```

The Doctors module can be accessed from the Home Dashboard by clicking **Doctors**.

---

# Project Structure

```text
src/
├── Controller/
│   ├── LoginController.php
│   ├── HomeController.php
│   └── DoctorController.php
│
├── Service/
│   ├── LoginService.php
│   ├── HomeService.php
│   └── DoctorService.php
│
├── Repository/
│   ├── LoginRepository.php
│   ├── HomeRepository.php
│   └── DoctorRepository.php
│
├── DAO/
│   ├── LoginDAO.php
│   ├── HomeDAO.php
│   └── DoctorDAO.php
│
├── DB/
│   └── DatabaseFunction.php
│
├── DTO/
│   └── DTO classes
│
├── Entity/
│   └── Database entities
│
└── Kernel.php

config/
└── Symfony configuration

public/
├── index.php
└── images/

templates/
├── base.html.twig
├── login/
│   └── login.html.twig
├── home/
│   └── dashboard.html.twig
└── doctor/
    └── doctors.html.twig

var/
└── Symfony cache and logs

vendor/
└── Composer dependencies

bin/
└── console
```

---

# Database

The project uses **PostgreSQL 17**.

Database administration is currently performed using **pgAdmin**.

### Current Database Functions

```text
function_login()
function_get_home_modules()
ecs_get_doctors_list()
```

### Current Database Tables

```text
users
ecs_doctors
```

---

# Installation

Clone the project and install the dependencies:

```bash
composer install
```

Configure the PostgreSQL database connection in:

```text
.env
```

Clear the Symfony cache:

```bash
php bin/console cache:clear
```

Start the Symfony development server:

```bash
symfony server:start
```

The application can then be accessed through the Symfony development server URL.

---

# Current Development Status

## Completed

* [x] Symfony project setup
* [x] PostgreSQL database setup
* [x] Doctrine DBAL configuration
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
* [x] Successful login handling
* [x] Failed login handling
* [x] Home Controller
* [x] Home Service
* [x] Home Repository
* [x] Home DAO
* [x] Home Dashboard
* [x] PostgreSQL home modules function
* [x] Dashboard module display
* [x] Doctor database table
* [x] Doctor PostgreSQL function
* [x] Doctor Controller
* [x] Doctor Service
* [x] Doctor Repository
* [x] Doctor DAO
* [x] Doctor DatabaseFunction integration
* [x] Doctor list page
* [x] Dashboard → Doctor module navigation

## In Progress

* [ ] Improve Doctor list UI
* [ ] Add remaining hospital modules

## Planned

* [ ] Patient Management
* [ ] Appointment Management
* [ ] Pharmacy Management
* [ ] FDM Module
* [ ] OP Consultation
* [ ] Prescription Management
* [ ] Billing Module
* [ ] Reports and Analytics
* [ ] Email Notifications
* [ ] User/session management
* [ ] Role-based access control

---

# Development Approach

The project is being developed incrementally.

Each hospital module is implemented through the following flow:

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

The goal is to keep the application modular, maintainable, and easy to understand while learning and implementing each layer step-by-step.

---

# Future Enhancements

The system will gradually be extended with additional hospital management modules, reporting, notifications, billing, prescriptions, appointments, user management, and other healthcare-related functionality.
