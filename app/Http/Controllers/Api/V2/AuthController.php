<?php

namespace App\Http\Controllers\Api\V2;

use App\Models\User;
use App\Services\WhatsAppNodeCampaignOtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends \App\Http\Controllers\Api\V1\AuthController
{
    public function register(Request $request)
    {
        return $this->registerComplete($request);
    }

    public function checkPhone(Request $request)
    {
        $minAgeDate = now()->subYears(18)->format('Y-m-d');

        $data = $request->validate([
            'country_code' => ['required', 'string', 'max:6'],
            'phone' => ['required', 'string', 'max:30'],
            'date_of_birth' => ['required', 'date', 'before_or_equal:' . $minAgeDate],
        ], [
            'date_of_birth.required' => 'Date of birth is required to verify you are at least 18 years old.',
            'date_of_birth.date' => 'Please enter a valid date of birth.',
            'date_of_birth.before_or_equal' => 'You must be at least 18 years old to create an account.',
        ]);

        $countryCode = $this->normalizeCountryCodeV2($data['country_code']);
        $phone = $this->normalizePhoneV2($data['phone']);

        $user = User::where('country_code', $countryCode)
            ->where('phone', $phone)
            ->first();

        if (! $user) {
            return response()->json([
                'exists' => false,
                'age_ok' => true,
                'message' => 'Phone number not registered.',
            ]);
        }

        if ((int) $user->is_active === 0) {
            return response()->json([
                'exists' => true,
                'active' => false,
                'age_ok' => true,
                'message' => 'Phone number not registered.',
            ]);
        }

        if ($user->date_of_birth && (int) $user->age < 18) {
            return response()->json([
                'exists' => true,
                'active' => true,
                'age_ok' => false,
                'message' => 'You must be at least 18 years old to use this service.',
            ]);
        }

        return response()->json([
            'exists' => true,
            'active' => true,
            'age_ok' => true,
            'user_id' => $user->id,
            'message' => 'Phone number exists.',
        ]);
    }

    public function registerSendOtp(Request $request, WhatsAppNodeCampaignOtpService $otp)
    {
        $data = $request->validate([
            'country_code' => ['required', 'string', 'max:6'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $cc = $this->normalizeCountryCodeV2($data['country_code']);
        $ph = $this->normalizePhoneV2($data['phone']);
        $phoneE164 = $cc . $ph;

        $exists = User::where('country_code', $cc)->where('phone', $ph)->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'phone' => 'Phone already exists.',
            ]);
        }

        $code = random_int(100000, 999999);
        $send = $otp->sendOtpViaNodeCampaign($phoneE164, $code);
        if (!($send['ok'] ?? false)) {
            return response()->json([
                'message' => 'Failed to send OTP.',
                'error' => $send,
            ], 502);
        }

        $otpToken = $otp->buildOtpToken('register', $phoneE164, $code);

        return response()->json([
            'message' => 'OTP sent.',
            'otp_token' => $otpToken,
            'expires_in' => $otp->ttlSeconds(),
        ], 200);
    }

    public function registerVerifyOtp(Request $request, WhatsAppNodeCampaignOtpService $otp)
    {
        $data = $request->validate([
            'country_code' => ['required', 'string', 'max:6'],
            'phone' => ['required', 'string', 'max:30'],
            'otp_token' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
        ]);

        $cc = $this->normalizeCountryCodeV2($data['country_code']);
        $ph = $this->normalizePhoneV2($data['phone']);
        $phoneE164 = $cc . $ph;

        $check = $otp->verifyOtpToken($data['otp_token'], 'register', $phoneE164, $data['code']);
        if (!($check['ok'] ?? false)) {
            return response()->json([
                'message' => 'Invalid OTP.',
                'error' => $check['error'] ?? 'invalid_otp',
            ], 422);
        }

        $verifiedToken = $this->buildVerifiedTokenV2('register_verified', $phoneE164, $otp->ttlSeconds());

        return response()->json([
            'message' => 'OTP verified.',
            'verified_token' => $verifiedToken,
            'expires_in' => $otp->ttlSeconds(),
        ], 200);
    }

    public function registerComplete(Request $request)
    {
        $minAgeDate = now()->subYears(18)->format('Y-m-d');

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'country_code' => ['required', 'string', 'max:6'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'phone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:6'],
            'verified_token' => ['required', 'string'],
            'date_of_birth' => ['required', 'date', 'before_or_equal:' . $minAgeDate],
            'gender' => ['nullable', 'in:male,female'],
            'location' => ['nullable', 'string', 'max:255'],
            'occupation' => ['nullable', 'string', 'max:150'],
            'education' => ['nullable', 'string', 'max:150'],
            'about_me' => ['nullable', 'string', 'max:2000'],
            'fcm_token' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', 'in:android,ios,web'],
            'interests' => ['nullable', 'array'],
            'interests.*' => ['integer', 'exists:interests,id'],
        ], [
            'date_of_birth.required' => 'Date of birth is required to verify you are at least 18 years old.',
            'date_of_birth.date' => 'Please enter a valid date of birth.',
            'date_of_birth.before_or_equal' => 'You must be at least 18 years old to create an account.',
        ]);

        $cc = $this->normalizeCountryCodeV2($data['country_code']);
        $ph = $this->normalizePhoneV2($data['phone']);
        $phoneE164 = $cc . $ph;

        $check = $this->verifyVerifiedTokenV2($data['verified_token'], 'register_verified', $phoneE164);
        if (!($check['ok'] ?? false)) {
            return response()->json([
                'message' => 'Phone not verified.',
                'error' => $check['error'] ?? 'invalid_verified_token',
            ], 422);
        }

        $existing = User::where('country_code', $cc)->where('phone', $ph)->first();
        if ($existing) {
            if ((int) $existing->is_active === 0) {
                throw ValidationException::withMessages([
                    'phone' => 'This account is deactivated. You cannot register with this phone.',
                ]);
            }

            throw ValidationException::withMessages([
                'phone' => 'Phone already exists for this country.',
            ]);
        }

        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'country_code' => $cc,
            'country_id' => $data['country_id'] ?? null,
            'phone' => $ph,
            'password' => Hash::make($data['password']),
            'date_of_birth' => $data['date_of_birth'],
            'gender' => $data['gender'] ?? null,
            'location' => $data['location'] ?? null,
            'occupation' => $data['occupation'] ?? null,
            'education' => $data['education'] ?? null,
            'about_me' => $data['about_me'] ?? null,
            'fcm_token' => $data['fcm_token'] ?? null,
            'platform' => $data['platform'] ?? null,
            'token_updated_at' => !empty($data['fcm_token']) ? now() : null,
            'is_active' => 1,
        ]);

        if (!empty($data['interests']) && is_array($data['interests'])) {
            $user->interests()->sync($data['interests']);
        }

        $user->load([
            'interests:id,name',
            'country:id,name,iso2,phone_code',
        ]);
        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'message' => 'Account created successfully.',
            'token' => $token,
            'user' => $user,
        ], 201);
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
        ]);

        if (array_key_exists('country_id', $data)) {
            $user = $request->user();
            if (! $user) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            if ((int) $user->is_active === 0) {
                return response()->json(['message' => 'This account is deactivated.'], 403);
            }

            $user->update([
                'country_id' => $data['country_id'],
            ]);
        }

        $response = parent::updateProfile($request);

        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        $payload = $response->getData(true);
        $userId = $payload['user']['id'] ?? null;

        if (!$userId) {
            return $response;
        }

        $user = User::query()
            ->with([
                'interests:id,name',
                'country:id,name,iso2,phone_code',
            ])
            ->find($userId);

        if (!$user) {
            return $response;
        }

        $payload['user'] = $user->toArray();

        return response()->json($payload, $response->getStatusCode());
    }

    public function sendNewPhoneOtp(Request $request, WhatsAppNodeCampaignOtpService $otp)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'new_country_code' => ['required', 'string', 'max:6'],
            'new_phone' => ['required', 'string', 'max:30'],
        ]);

        $newCc = $this->normalizeCountryCodeV2($data['new_country_code']);
        $newPh = $this->normalizePhoneV2($data['new_phone']);
        $newE164 = $newCc . $newPh;

        if ((string) $user->country_code === $newCc && (string) $user->phone === $newPh) {
            return response()->json(['message' => 'New phone must be different from current phone.'], 422);
        }

        $exists = User::where('country_code', $newCc)
            ->where('phone', $newPh)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Phone already exists.',
                'exists' => true,
            ], 422);
        }

        $code = random_int(100000, 999999);
        $send = $otp->sendOtpViaNodeCampaign($newE164, $code);
        if (!($send['ok'] ?? false)) {
            return response()->json([
                'message' => 'Failed to send OTP to new phone.',
                'error' => $send,
            ], 502);
        }

        $otpToken = $otp->buildOtpToken('update_phone_new', $newE164, $code);

        return response()->json([
            'success' => true,
            'message' => 'OTP sent to new phone.',
            'otp_token' => $otpToken,
            'expires_in' => $otp->ttlSeconds(),
        ], 200);
    }

    public function confirmNewPhoneWithOtp(Request $request, WhatsAppNodeCampaignOtpService $otp)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'new_country_code' => ['required', 'string', 'max:6'],
            'new_phone' => ['required', 'string', 'max:30'],
            'otp_token' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
        ]);

        $newCc = $this->normalizeCountryCodeV2($data['new_country_code']);
        $newPh = $this->normalizePhoneV2($data['new_phone']);
        $newE164 = $newCc . $newPh;

        if ((string) $user->country_code === $newCc && (string) $user->phone === $newPh) {
            return response()->json(['message' => 'New phone must be different from current phone.'], 422);
        }

        $check = $otp->verifyOtpToken($data['otp_token'], 'update_phone_new', $newE164, $data['code']);
        if (!($check['ok'] ?? false)) {
            return response()->json([
                'message' => 'Invalid OTP.',
                'error' => $check['error'] ?? 'invalid_otp',
            ], 422);
        }

        $exists = User::where('country_code', $newCc)
            ->where('phone', $newPh)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Phone already exists.',
                'exists' => true,
            ], 422);
        }

        $user->update([
            'country_code' => $newCc,
            'phone' => $newPh,
        ]);

        return response()->json([
            'message' => 'Phone updated successfully.',
            'exists' => false,
        ], 200);
    }

    private function buildVerifiedTokenV2(string $purpose, string $phoneE164, int $ttlSeconds): string
    {
        $payload = [
            'v' => 1,
            'purpose' => $purpose,
            'phone_e164' => $phoneE164,
            'exp' => now()->addSeconds($ttlSeconds)->timestamp,
        ];

        return Crypt::encryptString(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function verifyVerifiedTokenV2(string $token, string $purpose, string $phoneE164): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'invalid_token'];
        }

        if (($payload['purpose'] ?? null) !== $purpose) {
            return ['ok' => false, 'error' => 'wrong_purpose'];
        }
        if ((string) ($payload['phone_e164'] ?? '') !== $phoneE164) {
            return ['ok' => false, 'error' => 'wrong_phone'];
        }
        if ((int) ($payload['exp'] ?? 0) < now()->timestamp) {
            return ['ok' => false, 'error' => 'expired'];
        }

        return ['ok' => true];
    }

    private function normalizeCountryCodeV2(string $countryCode): string
    {
        $cc = preg_replace('/\s+/', '', trim($countryCode));
        if ($cc === '') {
            return $cc;
        }
        return $cc[0] === '+' ? $cc : ('+' . $cc);
    }

    private function normalizePhoneV2(string $phone): string
    {
        $p = preg_replace('/[\s\-]+/', '', trim($phone));
        return ltrim($p, '0');
    }
}
