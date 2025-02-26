<?php //
declare(strict_types=1);
namespace Src;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

$content =  [
                'subject'   => 'Test Subject D',
                'html'      => false,
                'bodyHTML'  => 'This is the body in plain text for HTML mail clients',
                'bodyAlt'   => 'This is the body in plain text for non-HTML mail clients',
            ];

try {
  (new SMTPEmail())->email($content);
  exit(0);
}
catch (Exception $e) {

}