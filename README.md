Hospital Management System (HMS)
Description

A Hospital Management System built with PHP and Symfony to manage patients, doctors, appointments, and other hospital operations.

Tech Stack
    PHP 8.3
    Symfony
    PostgreSQL
    Twig
    HTML, CSS, JavaScript
    Nginx

Installation
    composer install
    symfony server:start

Database Setup
    php bin/console doctrine:database:create

Features
    Patient Management
    Doctor Management
    Appointment Booking
    Authentication and Authorization
    Dashboard

Project Structure
    src/ - Application source code
    config/ - Symfony configuration
    public/ - Public entry point and assets
    templates/ - Twig templates
    var/ - Cache and logs

Future Enhancements
    Billing Module
    Prescription Management
    Email Notifications
    Reports and Analytics

What each folder does
Folder	Purpose

    src/	    --> Your PHP application code
    config/	    --> Framework configuration
    public/	    --> Public entry point (index.php)
    templates/	--> HTML templates (after installing Twig)
    var/	    --> Cache and logs
    vendor/	    --> Composer dependencies
    bin/console	--> Symfony command-line tool    