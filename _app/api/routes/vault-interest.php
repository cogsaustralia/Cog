<?php
declare(strict_types=1);

requireMethod('POST');
$db = getDB();
$body = jsonBody();

$name = sanitize($body['name'] ?? '');
$email = strtolower(sanitize($body['email'] ?? ''));
$pathway = sanitize($body['pathway'] ?? '');
$interest = sanitize($body['interest'] ?? '');
$notes = sanitize($body['notes'] ?? '');

if ($name === '') apiError('Name is required.');
if (!validateEmail($email)) apiError('A valid email is required.');

$stmt = $db->prepare('INSERT INTO vault_interests (full_name, email, pathway, interest, notes) VALUES (?,?,?,?,?)');
$stmt->execute([$name, $email, $pathway ?: null, $interest ?: null, $notes ?: null]);
$id = (int)$db->lastInsertId();
queueCrmSync($db, 'vault_interest', $id, ['name' => $name, 'email' => $email, 'pathway' => $pathway, 'interest' => $interest, 'notes' => $notes]);
processCrmQueue($db, 1);

apiSuccess(['saved' => true], 201);
