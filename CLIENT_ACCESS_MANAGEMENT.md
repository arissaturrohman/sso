# Client Access Management

## Overview
The client access management system allows you to control which users have access to which client applications. This is particularly useful for restricting access based on user roles (PBJ vs regular users) and organizational units (OPD).

## Database Structure

### client_access Table
- `id`: UUID primary key
- `user_id`: Foreign key to users table
- `client_id`: Foreign key to oauth_clients table
- `opd_id`: Foreign key to opds table (nullable)
- `is_allowed`: Boolean indicating if access is granted
- `created_at`: Timestamp
- `updated_at`: Timestamp

## Artisan Commands

### client-access:manage
Manage client access for users.

#### Usage
```bash
php artisan client-access:manage {action} [options]
```

#### Actions
1. **grant**: Grant access to client(s) for user(s)
2. **revoke**: Revoke access to client(s) for user(s)
3. **list**: List access for user(s) and client(s)
4. **reset**: Reset all access (requires confirmation)

#### Options
- `--user-id`: User ID
- `--user-email`: User email
- `--client-id`: Client ID
- `--client-name`: Client name
- `--opd-id`: OPD ID
- `--all-users`: Apply to all users
- `--all-clients`: Apply to all clients

#### Examples

1. **Grant access to a specific user for a specific client**
   ```bash
   php artisan client-access:grant --user-id=1 --client-name="SIM-UKPBJ"
   ```

2. **Grant access to a user for all clients**
   ```bash
   php artisan client-access:grant --user-email=user@example.com --all-clients
   ```

3. **Revoke access from a user for all clients**
   ```bash
   php artisan client-access:revoke --user-id=1 --all-clients
   ```

4. **List all access for a specific user**
   ```bash
   php artisan client-access:list --user-id=1
   ```

5. **List all access for a specific client**
   ```bash
   php artisan client-access:list --client-name="SIM-UKPBJ"
   ```

6. **List all access**
   ```bash
   php artisan client-access:list
   ```

7. **Reset all access (requires confirmation)**
   ```bash
   php artisan client-access:reset
   ```

## User Types and Access Rules

### PBJ Users (is_pbj = true)
- Can access all client applications
- No OPD restrictions
- See "Staf PBJ" badge on dashboard
- Access count shows total available clients

### Regular Users (is_pbj = false)
- Can only access clients they're explicitly granted access to
- Access is managed through the client_access table
- See "User OPD" badge on dashboard
- Access count shows only accessible clients

## Dashboard Integration

The dashboard shows:
- User type (PBJ or regular user)
- Number of accessible clients vs total clients
- Links to accessible applications
- Visual indicators for user type

## Implementation Details

### Controller Logic
- `DashboardController` checks user type and client access
- Uses `ClientAccess` model to determine allowed clients
- PBJ users get all clients, regular users get only allowed clients

### Seeder Data
- `ClientAccessSeeder` sets up initial access rules
- PBJ users get access to all clients
- Regular users get access to all clients by default (can be restricted)
- Sample restricted access rules for demonstration

### Model Relationships
- `ClientAccess` belongs to `User`
- `ClientAccess` belongs to `Client` (Passport client)
- `ClientAccess` belongs to `Opd` (optional)

## Future Enhancements

1. **OPD-based restrictions**: Automatically restrict access based on user's OPD
2. **API endpoints**: REST API for checking access and getting client lists
3. **Admin interface**: Web interface for managing client access
4. **Bulk operations**: CSV import/export for bulk access management
5. **Access logging**: Track access grant/revoke operations
6. **Time-based access**: Grant access for specific time periods

## Testing

### Test Cases

1. **PBJ User Access**
   - Login with PBJ user (ahmad@pbj.go.id)
   - Verify all clients are visible
   - Verify "Staf PBJ" badge is shown

2. **Regular User Access**
   - Login with regular user (test@example.com)
   - Verify only allowed clients are visible
   - Verify "User OPD" badge is shown

3. **Access Management**
   - Use `client-access:manage` commands to modify access
   - Verify changes are reflected immediately on dashboard

### Sample Users for Testing

- PBJ Users:
  - ahmad@pbj.go.id
  - siti@pbj.go.id
  - budi@pbj.go.id

- Regular Users:
  - admin@sso.test
  - test@example.com
  - test2@example.com
  - test3@example.com

### Sample Clients for Testing

- Delima
- SIM-UKPBJ
- Monitoring Proyek PBJ