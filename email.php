<?php
/**
 * Email Service using Brevo (Sendinblue) API
 */
require_once __DIR__ . '/config.php';

define('SENDER_EMAIL', getenv('SENDER_EMAIL') ?: 'noreply@expedisi.aplikasirt.my.id');
define('SENDER_NAME', getenv('SENDER_NAME') ?: 'LacakOngkir');
define('APP_URL', getenv('APP_URL') ?: 'https://expedisi.aplikasirt.my.id');

class EmailService {
    
    /**
     * Send email via Brevo API
     */
    public static function send(string $toEmail, string $toName, string $subject, string $htmlContent): bool {
        $data = [
            'sender' => [
                'name' => SENDER_NAME,
                'email' => SENDER_EMAIL,
            ],
            'to' => [
                ['email' => $toEmail, 'name' => $toName]
            ],
            'subject' => $subject,
            'htmlContent' => $htmlContent,
        ];

        $ch = curl_init(BREVO_BASE_URL . '/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'api-key: ' . BREVO_API_KEY,
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * Send verification email
     */
    public static function sendVerificationEmail(string $email, string $name, string $token): bool {
        $verifyUrl = APP_URL . '/api/auth.php?action=verify&token=' . $token;
        
        $subject = 'Verifikasi Email - LacakOngkir';
        $html = self::getVerificationEmailTemplate($name, $verifyUrl);
        
        return self::send($email, $name, $subject, $html);
    }

    /**
     * Send welcome email (after verification)
     */
    public static function sendWelcomeEmail(string $email, string $name, ?string $apiKey = null): bool {
        $subject = 'Selamat Datang di LacakOngkir!';
        $html = self::getWelcomeEmailTemplate($name, $apiKey);
        
        return self::send($email, $name, $subject, $html);
    }

    /**
     * Send password reset email
     */
    public static function sendPasswordResetEmail(string $email, string $name, string $token): bool {
        $resetUrl = APP_URL . '/api/auth.php?action=reset-password&token=' . $token;
        
        $subject = 'Reset Password - LacakOngkir';
        $html = self::getPasswordResetEmailTemplate($name, $resetUrl);
        
        return self::send($email, $name, $subject, $html);
    }

    private static function getVerificationEmailTemplate(string $name, string $verifyUrl): string {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Email</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:40px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background:#084823;padding:30px 40px;text-align:center;">
                            <h1 style="color:#fff;margin:0;font-size:24px;">🚛 LacakOngkir</h1>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style="padding:40px;">
                            <h2 style="color:#084823;margin:0 0 20px;font-size:22px;">Halo, ' . htmlspecialchars($name) . '!</h2>
                            <p style="color:#333;font-size:16px;line-height:1.6;margin:0 0 20px;">
                                Terima kasih telah mendaftar di LacakOngkir. Silakan verifikasi email kamu dengan klik tombol di bawah ini:
                            </p>
                            <p style="text-align:center;margin:30px 0;">
                                <a href="' . $verifyUrl . '" style="display:inline-block;background:#084823;color:#fff;text-decoration:none;padding:16px 40px;border-radius:8px;font-size:16px;font-weight:bold;">
                                    Verifikasi Email
                                </a>
                            </p>
                            <p style="color:#666;font-size:14px;line-height:1.6;">
                                Atau salin tautan ini ke browser:<br>
                                <a href="' . $verifyUrl . '" style="color:#0a5c2e;word-break:break-all;">' . $verifyUrl . '</a>
                            </p>
                            <p style="color:#999;font-size:12px;margin-top:30px;border-top:1px solid #eee;padding-top:20px;">
                                Tautan ini berlaku selama 24 jam. Jika kamu tidak merasa mendaftar, abaikan email ini.
                            </p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="background:#f9f9f9;padding:20px 40px;text-align:center;border-top:1px solid #eee;">
                            <p style="color:#999;font-size:12px;margin:0;">
                                © 2026 LacakOngkir - Expedisi Indonesia
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }

    private static function getWelcomeEmailTemplate(string $name, ?string $apiKey): string {
        $apiKeySection = '';
        if ($apiKey) {
            $apiKeySection = '<div style="background:#f4f4f4;border-radius:8px;padding:16px;margin:20px 0;">
                                <p style="margin:0 0 8px;color:#666;font-size:14px;">API Key kamu:</p>
                                <code style="color:#084823;font-size:14px;word-break:break-all;">' . htmlspecialchars($apiKey) . '</code>
                            </div>';
        }

        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Selamat Datang</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:40px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.1);">
                    <tr>
                        <td style="background:#084823;padding:30px 40px;text-align:center;">
                            <h1 style="color:#fff;margin:0;font-size:24px;">🚛 LacakOngkir</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:40px;">
                            <h2 style="color:#084823;margin:0 0 20px;font-size:22px;">Selamat datang, ' . htmlspecialchars($name) . '! 🎉</h2>
                            <p style="color:#333;font-size:16px;line-height:1.6;margin:0 0 20px;">
                                Email kamu telah diverifikasi. Sekarang kamu bisa menggunakan semua fitur LacakOngkir:
                            </p>
                            <ul style="color:#333;font-size:16px;line-height:2;margin:0 0 20px;padding-left:20px;">
                                <li>✅ Lacak paket dari berbagai kurir</li>
                                <li>✅ Hitung ongkir instan</li>
                                <li>✅ Generate API key untuk integrasi</li>
                            </ul>
                            ' . $apiKeySection . '
                            <p style="text-align:center;margin:30px 0;">
                                <a href="' . APP_URL . '" style="display:inline-block;background:#C4622D;color:#fff;text-decoration:none;padding:16px 40px;border-radius:8px;font-size:16px;font-weight:bold;">
                                    Mulai Sekarang
                                </a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f9f9f9;padding:20px 40px;text-align:center;border-top:1px solid #eee;">
                            <p style="color:#999;font-size:12px;margin:0;">
                                © 2026 LacakOngkir - Expedisi Indonesia
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }

    private static function getPasswordResetEmailTemplate(string $name, string $resetUrl): string {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:40px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.1);">
                    <tr>
                        <td style="background:#C4622D;padding:30px 40px;text-align:center;">
                            <h1 style="color:#fff;margin:0;font-size:24px;">🔑 Reset Password</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:40px;">
                            <h2 style="color:#084823;margin:0 0 20px;font-size:22px;">Halo, ' . htmlspecialchars($name) . '!</h2>
                            <p style="color:#333;font-size:16px;line-height:1.6;margin:0 0 20px;">
                                Kami menerima permintaan reset password untuk akun kamu. Klik tombol di bawah untuk reset password:
                            </p>
                            <p style="text-align:center;margin:30px 0;">
                                <a href="' . $resetUrl . '" style="display:inline-block;background:#C4622D;color:#fff;text-decoration:none;padding:16px 40px;border-radius:8px;font-size:16px;font-weight:bold;">
                                    Reset Password
                                </a>
                            </p>
                            <p style="color:#999;font-size:12px;margin-top:30px;border-top:1px solid #eee;padding-top:20px;">
                                Tautan ini berlaku selama 1 jam. Jika kamu tidak merasa minta reset password, abaikan email ini.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f9f9f9;padding:20px 40px;text-align:center;border-top:1px solid #eee;">
                            <p style="color:#999;font-size:12px;margin:0;">
                                © 2026 LacakOngkir - Expedisi Indonesia
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }
}
