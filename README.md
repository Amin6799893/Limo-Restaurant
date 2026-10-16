# LIMO — Corporate Meal Ordering Platform

**A web-based B2B meal ordering and management system designed for companies, factories, and corporate customers.**

LIMO streamlines daily meal ordering by connecting corporate customers with a centralized food-ordering and administration platform. It focuses on simplicity, controlled ordering, capacity management, and efficient administration.

## Overview

LIMO is a corporate food-ordering platform built for organizations that provide meals to employees or manage recurring meal orders for their workforce.

Unlike a public restaurant website, LIMO operates through an administrator-managed customer system. Authorized customers can access the daily menu and submit meal requests, while administrators manage customers, menus, order capacity, and order approvals.

The project is designed around a simple customer experience and a centralized administrative workflow.

## Key Features

### Customer Portal

- **Controlled Customer Access:** Customers are created and managed by an administrator; public self-registration is not part of the workflow.
- **Customer Authentication:** Customer access uses the assigned customer code and registered phone number.
- **Daily Menu:** Customers can view the menu available for the current day.
- **Availability-Based Ordering:** The customer interface focuses on menu availability rather than displaying meal prices.
- **Order Submission:** Customers can submit meal requests subject to the system's ordering rules and available capacity.
- **Clear User Experience:** A streamlined interface helps customers understand the available options and their order status.

### Administrative Management

- **Customer Management:** Maintain corporate customer records, including names, phone numbers, addresses, and customer codes.
- **Daily Menu Management:** Manage the menu available for the current day.
- **Capacity Management:** Control the number of meal requests that can be accepted.
- **Order Management:** Review and manage incoming meal requests.
- **Approval Workflow:** Reservations requiring administrative approval can be reviewed by the administrator.
- **Operational Controls:** Configure and manage the platform's ordering workflow.

### Business Rules

- **Daily Menu:** Only the current day's menu is presented to customers.
- **Friday Closure:** Ordering is disabled on Fridays.
- **Capacity Control:** Requests must respect the configured ordering capacity.
- **Administrator-Controlled Accounts:** Customer accounts are created by the administrator rather than through public registration.
- **Customer Data Persistence:** Resetting operational data should preserve customer records.

## Technology Stack

- **Backend:** PHP 8
- **Database:** SQLite
- **Frontend:** HTML5, CSS3, JavaScript
- **Database Access:** PHP SQLite support, using the database access method implemented in the project
- **Deployment Target:** PHP-compatible web hosting, including cPanel

## User Interface

LIMO follows a clean, mobile-friendly design direction intended to make daily ordering straightforward.

The visual identity uses a lime-green and icy-white color palette, with an emphasis on readability, clear order status, and simple navigation.

The interface is designed around two main areas:

- **Customer Portal:** Access to the daily menu and meal-ordering workflow.
- **Administration Panel:** Tools for managing customers, menus, capacity, and orders.

## Project Structure

The repository may contain the following application files and directories, depending on the deployed version:

| File or Directory | Purpose |
|---|---|
| `index.php` | PHP entry point, if used by the current version |
| `main.php` | Main application logic |
| `api.php` | Application API endpoints |
| `router.php` | Request routing |
| `index.html` | Frontend page structure |
| `app.js` | Client-side interaction and application logic |
| `style.css` | User interface styling |
| `data/` | Application data, where applicable |
| `backups/` | Backup storage, where applicable |
| `.htaccess` | Apache configuration, where applicable |

The actual repository structure may differ. Refer to the committed source files for the implementation details of the current version.

## Installation and Deployment

LIMO is intended for deployment on a PHP-compatible hosting environment.

A typical deployment process includes:

1. Configure a compatible PHP 8 runtime.
2. Verify that SQLite support is enabled.
3. Upload the application files to the hosting environment.
4. Configure the application's database and writable data directories.
5. Apply the required file permissions and server configuration.
6. Configure the administrator account securely.
7. Test customer authentication, daily menus, ordering capacity, approval workflows, and Friday closure.

Exact installation commands and configuration requirements depend on the current implementation and should be verified against the source code before deployment.

## Security Considerations

Security is important because the platform may handle customer contact details and corporate ordering information.

- Do not publish database files containing real customer data.
- Never commit passwords, API keys, or other secrets.
- Protect administrative routes and sessions.
- Validate customer input on the server side.
- Enforce ordering capacity and business rules on the backend.
- Restrict access to database files, backups, and application configuration.
- Change default administrator credentials before deployment if defaults are present.

## Project Goals

LIMO aims to provide a practical, maintainable solution for organizations that need a controlled daily meal-ordering workflow.

Its core goals are:

- Simplifying corporate meal ordering
- Centralizing customer and order management
- Enforcing daily ordering rules
- Preventing orders from exceeding available capacity
- Providing a clear and accessible interface
- Supporting deployment on conventional web hosting

## Project Status

LIMO is a corporate software project. Its current implementation and deployment readiness should be evaluated against the source code and the capabilities available in the deployed version.

## License

No open-source license has been specified. All rights remain with the respective rights holders unless a license is added to this repository.

## Author

**Amin6799893**

GitHub: [github.com/Amin6799893](https://github.com/Amin6799893)

