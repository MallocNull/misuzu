<?php
define('MSZ_CSRF_TOLERANCE', 15 * 60); // DO NOT EXCEED 16-BIT INTEGER SIZES, SHIT _WILL_ BREAK
define('MSZ_CSRF_HTML', '<input type="hidden" name="%1$s[%3$s]" value="%2$s">');
define('MSZ_CSRF_SECRET_STORE', '_msz_csrf_secret');
define('MSZ_CSRF_IDENTITY_STORE', '_msz_csrf_identity');
define('MSZ_CSRF_TOKEN_STORE', '_msz_csrf_tokens');
define('MSZ_CSRF_HASH_ALGO', 'sha256');
define('MSZ_CSRF_TOKEN_LENGTH', 76); // 8 + 4 + 64

// the following three functions DO NOT depend on csrf_init().
// $realm = Some kinda identifier for whatever's trying to do a validation.
// $identity = When the user is logged in I recommend just using their session key, otherwise IP will be fine.
function csrf_token_create(
    string $realm,
    string $identity,
    string $secretKey,
    ?int $timestamp = null,
    int $tolerance = MSZ_CSRF_TOLERANCE
): string {
    $timestamp = $timestamp ?? time();
    $token = bin2hex(pack('Vv', $timestamp, $tolerance));

    return $token . csrf_token_hash(
        MSZ_CSRF_HASH_ALGO,
        $realm,
        $identity,
        $secretKey,
        $timestamp,
        $tolerance
    );
}

function csrf_token_hash(
    string $algo,
    string $realm,
    string $identity,
    string $secretKey,
    int $timestamp,
    int $tolerance
): string {
    return hash_hmac(
        $algo,
        implode(',', [$realm, $identity, $timestamp, $tolerance]),
        $secretKey
    );
}

function csrf_token_verify(
    string $realm,
    string $token,
    string $identity,
    string $secretKey
): bool {
    if (empty($token) || strlen($token) !== MSZ_CSRF_TOKEN_LENGTH) {
        return false;
    }

    [$timestamp, $tolerance] = [0, 0];
    extract(unpack('Vtimestamp/vtolerance', hex2bin(substr($token, 0, 12))));

    if (time() > $timestamp + $tolerance) {
        return false;
    }

    // remove timestamp + tolerance from token
    $token = substr($token, 12);

    $compare = csrf_token_hash(
        MSZ_CSRF_HASH_ALGO,
        $realm,
        $identity,
        $secretKey,
        $timestamp,
        $tolerance
    );

    return hash_equals($compare, $token);
}

// Sets some defaults
function csrf_init(string $secretKey, string $identity): void
{
    $GLOBALS[MSZ_CSRF_SECRET_STORE] = $secretKey;
    $GLOBALS[MSZ_CSRF_IDENTITY_STORE] = $identity;
    $GLOBALS[MSZ_CSRF_TOKEN_STORE] = [];
}

function csrf_token(string $realm): string
{
    if (array_key_exists($realm, $GLOBALS[MSZ_CSRF_TOKEN_STORE])) {
        return $GLOBALS[MSZ_CSRF_TOKEN_STORE][$realm];
    }

    return $GLOBALS[MSZ_CSRF_TOKEN_STORE][$realm] = csrf_token_create(
        $realm,
        $GLOBALS[MSZ_CSRF_IDENTITY_STORE],
        $GLOBALS[MSZ_CSRF_SECRET_STORE]
    );
}

function csrf_verify(string $realm, $token): bool
{
    $token = (string)(is_array($token) && !empty($token[$realm]) ? $token[$realm] : $token);

    return csrf_token_verify(
        $realm,
        $token,
        $GLOBALS[MSZ_CSRF_IDENTITY_STORE],
        $GLOBALS[MSZ_CSRF_SECRET_STORE]
    );
}

function csrf_html(string $realm, string $name = 'csrf'): string
{
    return sprintf(MSZ_CSRF_HTML, $name, csrf_token($realm), $realm);
}

function csrf_http_header(string $realm, string $name = 'X-Misuzu-CSRF'): string
{
    return "{$name}: {$realm};" . csrf_token($realm);
}
