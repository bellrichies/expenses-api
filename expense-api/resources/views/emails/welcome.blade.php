<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Welcome to {{ $user->company->name }}</title>
    <style>
        body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
        .container { max-width: 600px; margin: 40px auto; padding: 0 20px; }
        .header { background: #4f46e5; color: #fff; padding: 24px; border-radius: 6px 6px 0 0; }
        .body { background: #fff; border: 1px solid #e5e7eb; padding: 24px; border-radius: 0 0 6px 6px; }
        .credentials { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 4px; padding: 16px; margin: 16px 0; }
        .footer { text-align: center; color: #6b7280; font-size: 12px; margin-top: 24px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Welcome, {{ $user->name }}!</h1>
    </div>
    <div class="body">
        <p>Your account has been created on the <strong>{{ $user->company->name }}</strong> expense management platform.</p>

        <div class="credentials">
            <p><strong>Your login credentials:</strong></p>
            <p>Email: <strong>{{ $user->email }}</strong></p>
            <p>Temporary password: <strong>{{ $temporaryPassword }}</strong></p>
            <p>Role: <strong>{{ $user->role->value }}</strong></p>
        </div>

        <p>Please log in and change your password as soon as possible.</p>
        <p>If you did not expect this email, please contact your company administrator.</p>
    </div>
    <div class="footer">
        <p>This is an automated message — please do not reply directly.</p>
    </div>
</div>
</body>
</html>
