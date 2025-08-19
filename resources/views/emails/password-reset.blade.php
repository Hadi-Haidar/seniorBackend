<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Password</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #4a4a4a;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f9f9f9;
        }
        .container {
            background-color: white;
            border-radius: 8px;
            padding: 40px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
        }
        .header {
            text-align: center;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .logo {
            font-size: 28px;
            font-weight: bold;
            color: #008080;
            white-space: nowrap;
            word-break: keep-all;
        }
        h2 {
            color: #2d3748;
            font-size: 24px;
            margin-bottom: 20px;
        }
        .button {
            display: inline-block;
            background-color: #008080;
            color: white !important;
            text-decoration: none;
            padding: 14px 40px;
            border-radius: 30px;
            margin: 25px 0;
            font-weight: 500;
            font-size: 16px;
            box-shadow: 0 4px 8px rgba(0, 128, 128, 0.2);
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            letter-spacing: 0.02em;
        }
        .button:hover {
            background-color: #006666;
            box-shadow: 0 6px 12px rgba(0, 128, 128, 0.3);
            transform: translateY(-1px);
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            text-align: center;
            font-size: 14px;
            color: #888;
        }
        .content {
            font-size: 16px;
        }

        /* Mobile Responsive for Title/Logo */
        @media only screen and (max-width: 600px) {
            .container {
                padding: 20px !important;
            }
            .logo {
                font-size: 20px !important;
            }
            h2 {
                font-size: 18px !important;
            }
        }

        @media only screen and (max-width: 480px) {
            .container {
                padding: 15px !important;
            }
            .logo {
                font-size: 18px !important;
            }
            h2 {
                font-size: 16px !important;
            }
        }

        @media only screen and (max-width: 360px) {
            .container {
                padding: 12px !important;
            }
            .logo {
                font-size: 16px !important;
            }
            h2 {
                font-size: 14px !important;
            }
        }

        @media only screen and (max-width: 320px) {
            .container {
                padding: 10px !important;
            }
            .logo {
                font-size: 14px !important;
            }
            h2 {
                font-size: 12px !important;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">{{ config('app.name', 'Tawasol') }}</div>
        </div>
        
        <div class="content">
            <h2>Reset Your Password</h2>
            
            <p>Hello {{ $user->name }},</p>
            
            <p>You are receiving this email because we received a password reset request for your account.</p>
            
            <div style="text-align: center;">
                <a href="{{ $resetUrl }}" class="button">Reset Password</a>
            </div>
            
            <p>This password reset link will expire in 60 minutes.</p>
            
            <p>If you did not request a password reset, no further action is required.</p>
        </div>
        
        <div class="footer">
            <p>© {{ date('Y') }} {{ config('app.name', 'Tawasol') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html> 