# LIMO — Corporate Meal Ordering Platform

**A corporate meal ordering and management system designed for companies, factories, and organizations.**

LIMO is a web-based B2B platform that simplifies daily meal ordering for corporate customers. It provides a customer-facing ordering interface and an administrative workflow for managing customer accounts, daily menus, order capacity, and reservation approvals.

Unlike a public restaurant website, LIMO is designed for organizations with administrator-managed customer access and controlled ordering workflows.

## Key Features

### Customer Portal
- Customer access using a registered phone number and assigned customer code.
- Daily menu viewing.
- Availability-focused meal selection without displaying prices.
- Meal request submission subject to ordering rules and capacity.
- A streamlined interface designed for everyday use.

### Administration
- Customer account management.
- Management of customer contact details and customer codes.
- Daily menu management.
- Order capacity controls.
- Review and approval of reservations.
- Centralized management of the ordering workflow.

### Business Rules
- Customers are created by an administrator; public self-registration is not part of the intended workflow.
- The customer portal displays the current day's menu.
- Ordering is disabled on Fridays.
- Orders are subject to configured capacity limits.
- Operational resets are intended to preserve customer records.

## Technology Stack

- **Backend:** PHP
- **Frontend:** HTML5, CSS3, JavaScript
- **Application API:** PHP
- **Web Server Configuration:** Apache `.htaccess`
- **Deployment Target:** PHP-compatible hosting, including cPanel

The database engine and specific runtime requirements should be confirmed against the current source code before deployment.

## Project Structure

```text
LIMO/
├── api.php
├── main.php
├── index.html
├── app.js
├── style.css
├── .htaccess
├── data/              # Application data — review before publishing
├── backups/           # Backup files — do not publish private data
└── _limo_backups/     # Additional backups — review before publishing
```

### Main Components

| File or Directory | Responsibility |
|---|---|
| `main.php` | Main PHP application logic |
| `api.php` | Backend API and request handling |
| `index.html` | Customer-facing page structure |
| `app.js` | Client-side interactions and application behavior |
| `style.css` | Layout, styling, and responsive interface |
| `.htaccess` | Apache rules and server configuration |
| `data/` | Application data, depending on the implementation |
| `backups/` | Backup storage; exclude private or production data from public releases |
| `_limo_backups/` | Additional backup storage; inspect before publishing |

## User Interface

LIMO uses a lime-green and icy-white visual direction, emphasizing readability, clear navigation, and a straightforward ordering experience.

The application is designed around two primary workflows:

- **Customer workflow:** Access the daily menu and submit meal requests.
- **Administrative workflow:** Manage customers, menus, capacity, and order approvals.

## Deployment

The project is structured as a PHP-based web application with a browser-based frontend.

Before deployment:

1. Configure a compatible PHP environment.
2. Verify the required PHP extensions and database configuration.
3. Upload the application files to a compatible web host.
4. Configure writable application data directories.
5. Review `.htaccess` rules and server permissions.
6. Secure administrator access and session handling.
7. Test customer authentication, menu availability, capacity limits, approval workflows, and Friday closure.

Exact installation steps depend on the current implementation and hosting configuration.

## Security and Privacy

Because LIMO may process customer contact details and corporate ordering information, security and privacy are important.

- Do not publish production databases or real customer records.
- Exclude private backups and configuration files.
- Never commit passwords, API keys, or other credentials.
- Protect administrative operations and authentication sessions.
- Validate requests on the server side.
- Enforce capacity limits and ordering rules on the backend.
- Review Apache rules and file permissions before deployment.

## Project Goals

LIMO aims to make corporate meal ordering more organized and manageable by providing:

- Centralized customer administration
- Daily menu management
- Controlled meal ordering
- Capacity-aware reservations
- Administrative approval workflows
- A simple, accessible user experience

## Project Status

LIMO is a corporate software project. Its implemented functionality and deployment requirements should be verified against the source code in the repository.

## License

No open-source license has been specified. All rights remain with the respective rights holders unless a license is added.

## Author

**Amin6799893**

GitHub: https://github.com/Amin6799893
