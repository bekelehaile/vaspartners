<?php

namespace App\Services;

use App\Models\Contact;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use phpseclib3\Crypt\RSA;
use RuntimeException;
use Throwable;

/**
 * Fayda (eSignet) OIDC — same pattern as fixedservices.
 * Contact profile is created/refreshed from userinfo on sign-in (no signup form).
 */
class EsignetService
{
    protected string $clientId;
    protected string $redirectUri;
    protected string $authorizationEndpoint;
    protected string $tokenEndpoint;
    protected string $userinfoEndpoint;
    protected string $clientAssertionType;
    protected string $privateKey;
    protected int $expirationTime;
    protected string $algorithm;

    public function __construct()
    {
        $config = config('services.esignet');
        foreach ($config as $key => $value) {
            $property = Str::camel($key);
            if (property_exists($this, $property)) {
                $this->{$property} = $value;
            }
        }
        $this->expirationTime = (int) ($this->expirationTime ?: 15);
        $this->algorithm = $this->algorithm ?: 'RS256';
    }

    public function buildAuthorizationUrl(): array
    {
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = bin2hex(random_bytes(16));

        $claims = [
            'userinfo' => [
                'name' => ['essential' => true],
                'phone_number' => ['essential' => true],
                'email' => ['essential' => true],
                'picture' => ['essential' => true],
                'gender' => ['essential' => true],
                'birthdate' => ['essential' => true],
                'address' => ['essential' => true],
                'nationality' => ['essential' => true],
            ],
            'id_token' => [
                'sub' => ['essential' => true],
            ],
        ];

        $authUrl = $this->authorizationEndpoint.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => 'openid profile email',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
            'claims' => json_encode($claims, JSON_UNESCAPED_SLASHES),
            'acr_values' => 'mosip:idp:acr:generated-code',
        ]);

        return [
            'status' => 'ok',
            'auth_url' => $authUrl,
            'code_verifier' => $verifier,
            'state' => $state,
        ];
    }

    public function exchangeCodeForToken(string $code, string $verifier): array
    {
        try {
            $assertion = $this->generateClientAssertion();
        } catch (Throwable) {
            return ['status' => 'error', 'message' => 'Client assertion generation failed.'];
        }

        $response = Http::asForm()->post($this->tokenEndpoint, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
            'code_verifier' => $verifier,
            'client_assertion' => $assertion,
            'client_assertion_type' => $this->clientAssertionType,
        ]);

        $json = $response->json();
        if (! isset($json['access_token'])) {
            return ['status' => 'error', 'message' => 'Token exchange failed.', 'details' => $json];
        }

        return ['status' => 'ok', 'token' => $json];
    }

    public function getUserInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get($this->userinfoEndpoint);
        if ($response->status() !== 200) {
            return ['status' => 'error', 'message' => 'Failed to fetch user info'];
        }

        $payload = $this->decodeJwtPayload($response->body());
        if (empty($payload['sub'])) {
            return ['status' => 'error', 'message' => 'Invalid user info payload'];
        }

        $result = $this->createOrUpdateContactBySub($payload);
        if ($result['status'] !== 'ok') {
            return $result;
        }

        return ['status' => 'ok', 'contact' => $result['contact']];
    }

    /**
     * Persist Fayda userinfo into contacts (fixedservices-compatible mapping).
     * First sign-in creates the row; later sign-ins refresh identity fields from Fayda.
     */
    public function createOrUpdateContactBySub(array $payload): array
    {
        $sub = $payload['sub'] ?? null;
        if (! $sub) {
            return ['status' => 'error', 'message' => 'Missing Fayda sub.'];
        }

        $rawPhone = $payload['phone_number'] ?? null;
        if (! $rawPhone) {
            return ['status' => 'error', 'message' => 'Missing phone number from Fayda.'];
        }

        $phoneNumber = \App\Support\PhoneNumber::normalize($rawPhone);
        if (strlen($phoneNumber) < 9 || ! \App\Support\PhoneNumber::isValidLocalMobile($phoneNumber)) {
            return ['status' => 'error', 'message' => 'Invalid phone number from Fayda.'];
        }

        $picture = $payload['picture'] ?? null;
        if ($picture && str_contains($picture, 'base64,')) {
            $picture = substr($picture, strpos($picture, 'base64,') + 7);
        }

        $birthdate = null;
        if (! empty($payload['birthdate'])) {
            $birthdate = date('Y-m-d', strtotime(str_replace('/', '-', $payload['birthdate'])));
        }

        $contact = Contact::query()->where('sub', $sub)->first();

        // Adopt invite / migrated / phone-OTP placeholders so tickets & memberships stay linked.
        if (! $contact) {
            $contact = Contact::query()
                ->where('phone_number', $phoneNumber)
                ->where(function ($q) {
                    $q->where('sub', 'like', 'invite-%')
                        ->orWhere('sub', 'like', 'mvas-contact-%')
                        ->orWhere('sub', 'like', 'otp-%');
                })
                ->first();
        }

        $contact ??= new Contact;

        $isNew = ! $contact->exists;

        $contact->syncFromFayda([
            'sub' => $sub,
            'name' => $payload['name'] ?? $contact->name ?? 'Contact',
            'phone_number' => $phoneNumber,
            'email' => $payload['email'] ?? $contact->email,
            'gender' => $payload['gender'] ?? $contact->gender,
            'nationality' => $payload['nationality'] ?? $contact->nationality,
            'identification_type' => $contact->identification_type ?: '2',
            'identification_number' => $contact->identification_number ?: $sub,
            'birthdate' => $birthdate ?? $contact->birthdate,
            'picture' => $picture ?? $contact->picture,
            'address' => $payload['address'] ?? $contact->address,
        ]);

        // Fayda / CRM verified contacts are always active.
        $contact->forceFill(['is_active' => true])->save();
        $contact->markIdentityVerified(\App\Enums\IdentityVerifiedVia::Fayda);

        $membership = app(CompanyMembershipService::class);

        // 1) Membership sync after Fayda verification (contact is active).
        try {
            $membership->trySyncMembershipsOnFaydaLogin($contact->fresh(['memberships.company']));
        } catch (Throwable $e) {
            report($e);
        }

        // 2) Owner sync — claim one ownerless migrated company only when still unlinked.
        try {
            $membership->tryAutoClaimMigratedCompanyByPhone($contact->fresh(['memberships.company']));
        } catch (Throwable $e) {
            // Never block Fayda login on claim failure; partner can complete profile manually.
            report($e);
        }

        // 3) Auto-approve + activate owned companies when profile is complete.
        try {
            $membership->autoApproveOwnedCompaniesAfterIdentityVerification(
                $contact->fresh(['memberships.company'])
            );
        } catch (Throwable $e) {
            report($e);
        }

        return ['status' => 'ok', 'contact' => $contact->fresh(['company', 'memberships.company'])];
    }

    protected function generateClientAssertion(): string
    {
        $key = $this->loadPrivateKey();
        $now = time();
        $b64 = fn ($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
        $h = $b64(json_encode(['alg' => $this->algorithm, 'typ' => 'JWT']));
        $p = $b64(json_encode([
            'iss' => $this->clientId,
            'sub' => $this->clientId,
            'aud' => $this->tokenEndpoint,
            'iat' => $now,
            'exp' => $now + ($this->expirationTime * 60),
            'jti' => bin2hex(random_bytes(16)),
        ]));

        return "{$h}.{$p}.".$b64($key->sign("{$h}.{$p}"));
    }

    protected function loadPrivateKey(): RSA
    {
        $decoded = base64_decode($this->privateKey, true);
        if ($decoded === false) {
            throw new RuntimeException('Private key base64 decode failed');
        }

        return RSA::loadPrivateKey($decoded, 'JWK')->withPadding(RSA::SIGNATURE_PKCS1);
    }

    public function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return [];
        }

        return json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) ?? [];
    }
}
