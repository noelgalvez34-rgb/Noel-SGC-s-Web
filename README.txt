SGC COOPERATIVE MANAGEMENT SYSTEM
=================================

REQUIREMENTS
- XAMPP with Apache, MySQL and PHP 8+
- Web browser

INSTALLATION
1. Extract the folder "sgc_cooperative_system" into C:\xampp\htdocs\
2. Open XAMPP Control Panel.
3. Start Apache and MySQL.
4. Open http://localhost/phpmyadmin
5. Click Import and select database.sql from this project folder.
6. Open http://localhost/sgc_cooperative_system/setup.php
7. When setup is complete, open http://localhost/sgc_cooperative_system/

DEMO ACCOUNTS
Manager: manager / password123
Clerk:   clerk / password123

BUYER ACCOUNT
Use "Create buyer account" on the login page to register a buyer and then log in.

IMPORTANT SECURITY NOTES
- Change the demo passwords immediately.
- Delete setup.php after initial setup.
- This is a student/local development starter. Before public internet deployment,
  configure HTTPS, stronger operational controls, backups, server permissions,
  and a security review.
- Configure DB_HOST, DB_NAME, DB_USER, DB_PASS in config/config.php if needed.

FEATURES
- Role-based login (Manager, Clerk, Buyer)
- Buyer registration
- Farmer records and delivery history
- Delivery entry with automatic inventory increase
- Grain inventory with on-hand, reserved and available quantities
- Buyer records
- Online and clerk-encoded orders
- Stock validation when approving orders
- Stock reservation and release workflow
- Printable order confirmation
- Date-range operational reports
- User activation/deactivation
- Password change

WORKFLOW NOTES
- A delivery entered through the current delivery form is saved as Accepted and increases on-hand stock.
- Orders begin as Pending.
- Manager/Clerk can approve only if enough available stock exists.
- Approved orders reserve stock.
- Marking an approved order Released deducts on-hand and reserved quantities.
- Cancelling an approved order releases the reservation.
