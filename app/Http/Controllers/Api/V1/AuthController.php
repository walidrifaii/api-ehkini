<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Friendship;
use App\Models\GiftTransaction;
use App\Models\User;
use App\Services\ImageCompressionService;
use App\Services\UserLocationService;
use App\Support\MediaStorage;
use App\Services\OtpDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Laravel `max` rule for uploads: size in kilobytes (2048 KB = 2 MB). */
    public const PROFILE_IMAGE_MAX_KB = 2048;

    /**
     * POST /api/v1/register
     * Legacy direct register (without OTP). Keep if you still need backward compatibility.
     */
    public function register(Request $request)
    {
        $minAgeDate = now()->subYears(18)->format('Y-m-d');

        $data = $request->validate([
            'first_name'    => ['required', 'string', 'max:100'],
            'last_name'     => ['required', 'string', 'max:100'],

            'country_code'  => ['required', 'string', 'max:6'],
            'phone'         => ['required', 'string', 'max:30'],

            'password'      => ['required', 'string', 'min:6'],

            'date_of_birth' => ['required', 'date', 'before_or_equal:' . $minAgeDate],
            'gender'        => ['nullable', 'in:male,female'],

            'location'      => ['nullable', 'string', 'max:255'],
            'occupation'    => ['nullable', 'string', 'max:150'],
            'education'     => ['nullable', 'string', 'max:150'],
            'about_me'      => ['nullable', 'string', 'max:2000'],

            'profile_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::PROFILE_IMAGE_MAX_KB],

            'fcm_token'     => ['nullable', 'string', 'max:512'],
            'platform'      => ['nullable', 'in:android,ios,web'],

            'interests'     => ['nullable', 'array'],
            'interests.*'   => ['integer', 'exists:interests,id'],
        ] + $this->coordinatesRules(), array_merge(
            $this->dateOfBirthValidationMessages(required: true),
            $this->profileImageValidationMessages()
        ));

        $data['country_code'] = $this->normalizeCountryCode($data['country_code']);
        $data['phone'] = $this->normalizePhone($data['phone']);

        $existing = User::where('country_code', $data['country_code'])
            ->where('phone', $data['phone'])
            ->first();

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

        $profileImagePath = null;

        try {
            DB::beginTransaction();

            if ($request->hasFile('profile_image')) {
                $profileImagePath = app(ImageCompressionService::class)->storeCompressedJpeg(
                    $request->file('profile_image'),
                    MediaStorage::diskName(),
                    'profiles',
                    ImageCompressionService::PROFILE_MAX_SIDE
                );
                if (! $profileImagePath) {
                    throw new \Exception('Profile image upload failed.');
                }
            }

            $user = User::create($this->withLocationTimestamp([
                'first_name'       => $data['first_name'],
                'last_name'        => $data['last_name'],
                'profile_image'    => $profileImagePath,

                'country_code'     => $data['country_code'],
                'phone'            => $data['phone'],

                'password'         => Hash::make($data['password']),

                'date_of_birth'    => $data['date_of_birth'] ?? null,
                'gender'           => $data['gender'] ?? null,

                'location'         => $data['location'] ?? null,
                'latitude'         => $data['latitude'] ?? null,
                'longitude'        => $data['longitude'] ?? null,
                'occupation'       => $data['occupation'] ?? null,
                'education'        => $data['education'] ?? null,
                'about_me'         => $data['about_me'] ?? null,

                'fcm_token'        => $data['fcm_token'] ?? null,
                'platform'         => $data['platform'] ?? null,
                'token_updated_at' => !empty($data['fcm_token']) ? now() : null,

                'is_active'        => 1,
            ]));

            if (!empty($data['interests']) && is_array($data['interests'])) {
                $user->interests()->sync($data['interests']);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            if ($profileImagePath) {
                MediaStorage::delete($profileImagePath);
            }

            return response()->json([
                'message' => 'Register failed.',
                'error'   => $e->getMessage(),
            ], 422);
        }

        $user->load('interests:id,name');
        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'message' => 'Account created successfully.',
            'token'   => $token,
            'user'    => $user,
        ], 201);
    }

    /**
     * POST /api/v1/check-phone
     */
     public function checkPhone(Request $request)
    {
        $minAgeDate = now()->subYears(18)->format('Y-m-d');

        $data = $request->validate([
            'country_code'  => ['required', 'string', 'max:6'],
            'phone'         => ['required', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:' . $minAgeDate],
        ], $this->dateOfBirthValidationMessages());

        $countryCode = $this->normalizeCountryCode($data['country_code']);
        $phone = $this->normalizePhone($data['phone']);

        $user = User::where('country_code', $countryCode)
            ->where('phone', $phone)
            ->first();

        if (! $user) {
            return response()->json([
                'exists' => false,
                'message' => 'Phone number not registered.',
            ]);
        }

        if ((int) $user->is_active === 0) {
            return response()->json([
                'exists' => true,
                'active' => false,
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

    /**
     * POST /api/v1/login
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'country_code' => ['required', 'string', 'max:6'],
            'phone'        => ['required', 'string', 'max:30'],
            'password'     => ['required', 'string'],

            'fcm_token'    => ['nullable', 'string', 'max:512'],
            'platform'     => ['nullable', 'in:android,ios,web'],
            'location'     => ['nullable', 'string', 'max:255'],
        ] + $this->coordinatesRules());

        $data['country_code'] = $this->normalizeCountryCode($data['country_code']);
        $data['phone'] = $this->normalizePhone($data['phone']);

        $user = User::where('country_code', $data['country_code'])
            ->where('phone', $data['phone'])
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if ((int) $user->is_active === 0) {
            return response()->json(['message' => 'This account is Deleted.'], 403);
        }

        $user->tokens()->delete();

        $update = [];

        if (!empty($data['fcm_token'])) {
            $update['fcm_token'] = $data['fcm_token'];
            $update['platform'] = $data['platform'] ?? $user->platform;
            $update['token_updated_at'] = now();
        }

        if (array_key_exists('location', $data) && $data['location'] !== null) {
            $update['location'] = $data['location'];
        }

        if (array_key_exists('latitude', $data) && $data['latitude'] !== null) {
            $update['latitude'] = $data['latitude'];
        }

        if (array_key_exists('longitude', $data) && $data['longitude'] !== null) {
            $update['longitude'] = $data['longitude'];
        }

        if (isset($update['latitude'], $update['longitude'])) {
            $update['location_updated_at'] = now();
        }

        if (!empty($update)) {
            $user->update($update);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'token'   => $token,
            'user'    => $user,
        ]);
    }

    /**
     * GET /api/v1/me
     */
    public function me(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user->load('interests:id,name');

        $connections = Friendship::countAcceptedConnectionsFor((int) $user->id);

        $giftsSent = GiftTransaction::where('sender_id', $user->id)->count();
        $giftsReceived = GiftTransaction::where('receiver_id', $user->id)->count();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,

                'profile_image' => $user->profile_image,
                'profile_image_url' => $user->profile_image_url,

                'country_code' => $user->country_code,
                'phone' => $user->phone,

                'date_of_birth' => $user->date_of_birth,
                'age' => $user->age,

                'gender' => $user->gender,
                'location' => $user->location,
                'latitude' => $user->latitude,
                'longitude' => $user->longitude,
                'location_updated_at' => $user->location_updated_at,
                'location_sharing_enabled' => (bool) $user->location_sharing_enabled,

                'occupation' => $user->occupation,
                'education' => $user->education,
                'about_me' => $user->about_me,

                'platform' => $user->platform,
                'interests' => $user->interests,

                'counts' => [
                    'connections' => $connections,
                    'gifts_sent' => $giftsSent,
                    'gifts_received' => $giftsReceived,
                ],

                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
        ]);
    }

    /**
     * POST /api/v1/profile/update
     * POST /api/v2/profile/update
     *
     * JSON or multipart. Bio: send "bio" or "about_me". Location: free text.
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ((int) $user->is_active === 0) {
            return response()->json(['message' => 'This account is deactivated.'], 403);
        }

        $this->prepareProfileUpdateRequest($request);

        $data = $request->validate(
            $this->profileUpdateRules(),
            $this->profileUpdateMessages()
        );

        $newImagePath = null;
        $oldImagePath = $user->profile_image;

        try {
            DB::beginTransaction();

            if ($request->hasFile('profile_image')) {
                $newImagePath = app(ImageCompressionService::class)->storeCompressedJpeg(
                    $request->file('profile_image'),
                    MediaStorage::diskName(),
                    'profiles',
                    ImageCompressionService::PROFILE_MAX_SIDE
                );
                if (! $newImagePath) {
                    throw new \Exception('Profile image upload failed.');
                }
                $data['profile_image'] = $newImagePath;
            }

            $updateFields = collect($data)->except(['interests', 'bio'])->toArray();
            if (! empty($updateFields['fcm_token'])) {
                $updateFields['token_updated_at'] = now();
            }
            $updateFields = $this->withLocationTimestamp($updateFields);
            if (!empty($updateFields)) {
                $user->update($updateFields);
            }

            if (array_key_exists('interests', $data)) {
                $user->interests()->sync($data['interests'] ?? []);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            if ($newImagePath) {
                MediaStorage::delete($newImagePath);
            }

            return response()->json([
                'message' => 'Update profile failed.',
                'error'   => $e->getMessage(),
            ], 422);
        }

        if ($newImagePath && $oldImagePath) {
            MediaStorage::delete($oldImagePath);
        }

        $user->refresh()->load('interests:id,name');

        $userData = $user->toArray();
        $userData['bio'] = $user->about_me;

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user'    => $userData,
        ]);
    }

    /**
     * POST /api/v1/profile/location
     * POST /api/v2/profile/location
     * Lightweight GPS sync (no full profile payload).
     */
    public function updateLocation(Request $request, UserLocationService $locationService)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => api_trans('unauthenticated')], 401);
        }

        if ((int) $user->is_active === 0) {
            return response()->json(['message' => api_trans('account_deactivated')], 403);
        }

        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'location' => ['nullable', 'string', 'max:255'],
            'force' => ['nullable', 'boolean'],
        ]);

        $force = filter_var($data['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($force) {
            $user->update([
                'latitude' => (float) $data['latitude'],
                'longitude' => (float) $data['longitude'],
                'location' => $data['location'] ?? $user->location,
                'location_sharing_enabled' => true,
                'location_updated_at' => now(),
            ]);
            $user->refresh();
            $result = [
                'updated' => true,
                'latitude' => (float) $user->latitude,
                'longitude' => (float) $user->longitude,
                'location' => $user->location,
                'location_sharing_enabled' => true,
                'location_updated_at' => $user->location_updated_at,
            ];
        } else {
            if (! $user->location_sharing_enabled) {
                return response()->json([
                    'message' => api_trans('location_sharing_disabled'),
                    'updated' => false,
                    'location' => [
                        'latitude' => $user->latitude !== null ? (float) $user->latitude : null,
                        'longitude' => $user->longitude !== null ? (float) $user->longitude : null,
                        'location' => $user->location,
                        'location_sharing_enabled' => false,
                        'location_updated_at' => $user->location_updated_at,
                    ],
                ]);
            }

            $result = $locationService->enableAndUpdate(
                $user,
                (float) $data['latitude'],
                (float) $data['longitude'],
                $data['location'] ?? null
            );
        }

        return response()->json([
            'message' => api_trans($result['updated']
                ? 'location_updated_successfully'
                : 'location_unchanged'),
            'updated' => $result['updated'],
            'location' => [
                'latitude' => $result['latitude'],
                'longitude' => $result['longitude'],
                'location' => $result['location'],
                'location_sharing_enabled' => $result['location_sharing_enabled'],
                'location_updated_at' => $result['location_updated_at'],
            ],
        ]);
    }

    /**
     * POST /api/v1/profile/location/disable
     * POST /api/v2/profile/location/disable
     */
    public function disableLocation(Request $request, UserLocationService $locationService)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => api_trans('unauthenticated')], 401);
        }

        if ((int) $user->is_active === 0) {
            return response()->json(['message' => api_trans('account_deactivated')], 403);
        }

        $result = $locationService->disable($user);

        return response()->json([
            'message' => api_trans('location_sharing_disabled'),
            'location' => [
                'latitude' => $result['latitude'],
                'longitude' => $result['longitude'],
                'location' => $result['location'],
                'location_sharing_enabled' => $result['location_sharing_enabled'],
                'location_updated_at' => $result['location_updated_at'],
            ],
        ]);
    }

    /**
     * Map mobile field names and accept JSON or form body.
     */
    protected function prepareProfileUpdateRequest(Request $request): void
    {
        if ($request->has('bio') && ! $request->has('about_me')) {
            $request->merge(['about_me' => $request->input('bio')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function profileUpdateRules(): array
    {
        $minAgeDate = now()->subYears(18)->format('Y-m-d');

        return [
            'first_name'    => ['nullable', 'string', 'max:100'],
            'last_name'     => ['nullable', 'string', 'max:100'],

            'date_of_birth' => ['nullable', 'date', 'before_or_equal:'.$minAgeDate],
            'gender'        => ['nullable', 'in:male,female'],

            'location'      => ['nullable', 'string', 'max:255'],
            'occupation'    => ['nullable', 'string', 'max:150'],
            'education'     => ['nullable', 'string', 'max:150'],
            'about_me'      => ['nullable', 'string', 'max:2000'],
            'bio'           => ['nullable', 'string', 'max:2000'],

            'country_id'    => ['nullable', 'integer', 'exists:countries,id'],

            'profile_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::PROFILE_IMAGE_MAX_KB],

            'interests'     => ['nullable', 'array'],
            'interests.*'   => ['integer', 'exists:interests,id'],

            'fcm_token'     => ['nullable', 'string', 'max:512'],
            'platform'      => ['nullable', 'in:android,ios,web'],

            ...$this->coordinatesRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function coordinatesRules(): array
    {
        return [
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withLocationTimestamp(array $data): array
    {
        if (
            array_key_exists('latitude', $data)
            && array_key_exists('longitude', $data)
            && $data['latitude'] !== null
            && $data['longitude'] !== null
        ) {
            $data['location_updated_at'] = now();
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    protected function profileUpdateMessages(): array
    {
        return array_merge(
            $this->dateOfBirthValidationMessages(),
            $this->profileImageValidationMessages(),
            [
                'location.string' => api_trans('location_must_be_text'),
            ]
        );
    }

    /**
     * @return array<string, string>
     */
    protected function dateOfBirthValidationMessages(bool $required = false): array
    {
        $messages = [
            'date_of_birth.date' => api_trans('date_of_birth_invalid'),
            'date_of_birth.before_or_equal' => api_trans($required ? 'must_be_18_to_register' : 'must_be_18'),
        ];

        if ($required) {
            $messages['date_of_birth.required'] = api_trans('date_of_birth_required_for_age');
        }

        return $messages;
    }

    /**
     * @return array<string, string>
     */
    protected function profileImageValidationMessages(): array
    {
        $size = [
            'max_kb' => (string) self::PROFILE_IMAGE_MAX_KB,
            'max_mb' => $this->profileImageMaxMbLabel(),
        ];

        return [
            'profile_image.image' => api_trans('profile_image_must_be_image'),
            'profile_image.mimes' => api_trans('profile_image_invalid_type'),
            'profile_image.max' => api_trans('profile_image_max_size', $size),
            'profile_image.uploaded' => api_trans('profile_image_max_size', $size),
        ];
    }

    protected function profileImageMaxMbLabel(): string
    {
        $mb = self::PROFILE_IMAGE_MAX_KB / 1024;

        return rtrim(rtrim(number_format($mb, 1), '0'), '.') ?: (string) $mb;
    }

    /**
     * POST /api/v1/profile/image/delete
     */
    public function deleteProfileImage(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ((int) $user->is_active === 0) {
            return response()->json(['message' => 'This account is deactivated.'], 403);
        }

        if (! $user->profile_image) {
            return response()->json([
                'message' => 'No profile image to delete.',
            ], 422);
        }

        try {
            DB::beginTransaction();

            MediaStorage::delete($user->profile_image);

            $user->update([
                'profile_image' => null,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to delete profile image.',
                'error'   => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Profile image deleted successfully.',
            'user' => [
                'id' => $user->id,
                'profile_image' => null,
                'profile_image_url' => null,
            ],
        ]);
    }

    /**
     * POST /api/v1/account/deactivate
     */
    public function deactivateAccount(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            DB::beginTransaction();

            $user->update([
                'is_active' => 0,
                'fcm_token' => null,
                'platform'  => null,
                'token_updated_at' => null,
            ]);

            $user->tokens()->delete();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to Deleted account.',
                'error'   => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Account Deleted successfully.',
        ]);
    }

    /**
     * POST /api/v1/logout
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token = $request->user()->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        $user->update([
            'fcm_token'        => null,
            'platform'         => null,
            'token_updated_at' => null,
        ]);

        return response()->json(['message' => 'Logged out successfully.']);
    }

    // ---------------------------
    // FORGOT PASSWORD OTP FLOW
    // ---------------------------

    public function forgotPasswordSendOtp(Request $request, OtpDeliveryService $otp)
    {
        $data = $request->validate([
            'country_code' => ['required', 'string', 'max:6'],
            'phone'        => ['required', 'string', 'max:30'],
            'channel'      => ['nullable', 'string', 'in:whatsapp,whatsapp_node,sms'],
        ]);

        $cc = $this->normalizeCountryCode($data['country_code']);
        $ph = $this->normalizePhone($data['phone']);
        $phoneE164 = $cc . $ph;

        $user = User::where('country_code', $cc)->where('phone', $ph)->first();

        if (! $user || (int) $user->is_active === 0) {
            return response()->json(['message' => 'If the phone exists, we sent a code.'], 200);
        }

        $send = $otp->sendOtp('forgot_password', $cc, $ph, $data['channel'] ?? null);
        if (! ($send['ok'] ?? false)) {
            return response()->json([
                'message' => 'Failed to send OTP.',
                'error' => $send['error'] ?? null,
            ], 502);
        }

        return response()->json([
            'message' => 'OTP sent.',
            'otp_token' => $send['otp_token'],
            'channel' => $send['channel'] ?? null,
            'expires_in' => $send['expires_in'] ?? $otp->ttlSeconds(),
        ], 200);
    }

    public function verifyForgotPasswordOtp(Request $request, OtpDeliveryService $otp)
    {
        $data = $request->validate([
            'country_code' => ['required', 'string', 'max:6'],
            'phone'        => ['required', 'string', 'max:30'],
            'otp_token'    => ['required', 'string'],
            'code'         => ['required', 'digits:6'],
        ]);

        $cc = $this->normalizeCountryCode($data['country_code']);
        $ph = $this->normalizePhone($data['phone']);
        $phoneE164 = $cc . $ph;

        $check = $otp->verifyOtp($data['otp_token'], 'forgot_password', $phoneE164, $data['code']);
        if (!($check['ok'] ?? false)) {
            return response()->json([
                'message' => 'Invalid OTP.',
                'error'   => $check['error'] ?? 'invalid_otp',
            ], 422);
        }

        // Local verified-token build to avoid dependency on missing service method in old deployments.
        $verifiedToken = $this->buildVerifiedTokenLocal('forgot_password_verified', $phoneE164, $otp->ttlSeconds());

        return response()->json([
            'message'        => 'OTP verified.',
            'verified_token' => $verifiedToken,
            'expires_in'     => $otp->ttlSeconds(),
        ], 200);
    }

    public function resetPasswordAfterOtp(Request $request, OtpDeliveryService $otp)
    {
        $data = $request->validate([
            'country_code'              => ['required', 'string', 'max:6'],
            'phone'                     => ['required', 'string', 'max:30'],
            'verified_token'            => ['required', 'string'],
            'new_password'              => ['required', 'string', 'min:6', 'confirmed'],
            'new_password_confirmation' => ['required', 'string', 'min:6'],
        ]);

        $cc = $this->normalizeCountryCode($data['country_code']);
        $ph = $this->normalizePhone($data['phone']);
        $phoneE164 = $cc . $ph;

        $check = $this->verifyVerifiedTokenLocal($data['verified_token'], 'forgot_password_verified', $phoneE164);

        if (!($check['ok'] ?? false)) {
            return response()->json([
                'message' => 'Invalid verification token.',
                'error'   => $check['error'] ?? 'invalid_verified_token',
            ], 422);
        }

        $user = User::where('country_code', $cc)->where('phone', $ph)->first();
        if (! $user || (int) $user->is_active === 0) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->password = Hash::make($data['new_password']);
        $user->save();
        $user->tokens()->delete();

        return response()->json(['message' => 'Password updated.'], 200);
    }

    // ---------------------------
    // REGISTER OTP FLOW (commented out — registration uses POST /api/v1/register without OTP)
    // ---------------------------
    /*
    public function registerSendOtp(Request $request, WhatsAppNodeCampaignOtpService $otp)
    {
        $data = $request->validate([
            'country_code' => ['required', 'string', 'max:6'],
            'phone'        => ['required', 'string', 'max:30'],
        ]);

        $cc = $this->normalizeCountryCode($data['country_code']);
        $ph = $this->normalizePhone($data['phone']);
        $phoneE164 = $cc . $ph;

        $exists = User::where('country_code', $cc)->where('phone', $ph)->exists();
        if ($exists) {
            return response()->json(['message' => 'Phone already exists.'], 422);
        }

        $code = random_int(100000, 999999);

        $send = $otp->sendOtpViaNodeCampaign($phoneE164, $code);
        if (!($send['ok'] ?? false)) {
            return response()->json([
                'message' => 'Failed to send OTP.',
                'error'   => $send,
            ], 502);
        }

        $otpToken = $otp->buildOtpToken('register', $phoneE164, $code);

        return response()->json([
            'message'    => 'OTP sent.',
            'otp_token'  => $otpToken,
            'expires_in' => $otp->ttlSeconds(),
        ], 200);
    }

    public function registerVerifyOtp(Request $request, WhatsAppNodeCampaignOtpService $otp)
    {
        $data = $request->validate([
            'country_code' => ['required', 'string', 'max:6'],
            'phone'        => ['required', 'string', 'max:30'],
            'otp_token'    => ['required', 'string'],
            'code'         => ['required', 'digits:6'],
        ]);

        $cc = $this->normalizeCountryCode($data['country_code']);
        $ph = $this->normalizePhone($data['phone']);
        $phoneE164 = $cc . $ph;

        $check = $otp->verifyOtpToken($data['otp_token'], 'register', $phoneE164, $data['code']);
        if (!($check['ok'] ?? false)) {
            return response()->json([
                'message' => 'Invalid OTP.',
                'error'   => $check['error'] ?? 'invalid_otp',
            ], 422);
        }

        $verifiedToken = $this->buildVerifiedTokenLocal('register_verified', $phoneE164, $otp->ttlSeconds());

        return response()->json([
            'message'        => 'OTP verified.',
            'verified_token' => $verifiedToken,
            'expires_in'     => $otp->ttlSeconds(),
        ], 200);
    }

    public function registerComplete(Request $request)
    {
        $data = $request->validate([
            'first_name'     => ['required', 'string', 'max:100'],
            'last_name'      => ['required', 'string', 'max:100'],
            'country_code'   => ['required', 'string', 'max:6'],
            'phone'          => ['required', 'string', 'max:30'],
            'password'       => ['required', 'string', 'min:6'],
            'verified_token' => ['required', 'string'],

            'date_of_birth'  => ['nullable', 'date'],
            'gender'         => ['nullable', 'in:male,female'],

            'location'       => ['nullable', 'string', 'max:255'],
            'occupation'     => ['nullable', 'string', 'max:150'],
            'education'      => ['nullable', 'string', 'max:150'],
            'about_me'       => ['nullable', 'string', 'max:2000'],

            'fcm_token'      => ['nullable', 'string', 'max:512'],
            'platform'       => ['nullable', 'in:android,ios,web'],

            'interests'      => ['nullable', 'array'],
            'interests.*'    => ['integer', 'exists:interests,id'],
        ]);

        $cc = $this->normalizeCountryCode($data['country_code']);
        $ph = $this->normalizePhone($data['phone']);
        $phoneE164 = $cc . $ph;

        $check = $this->verifyVerifiedTokenLocal($data['verified_token'], 'register_verified', $phoneE164);
        if (!($check['ok'] ?? false)) {
            return response()->json([
                'message' => 'Phone not verified.',
                'error'   => $check['error'] ?? 'invalid_verified_token',
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
            'first_name'       => $data['first_name'],
            'last_name'        => $data['last_name'],

            'country_code'     => $cc,
            'phone'            => $ph,

            'password'         => Hash::make($data['password']),

            'date_of_birth'    => $data['date_of_birth'] ?? null,
            'gender'           => $data['gender'] ?? null,

            'location'         => $data['location'] ?? null,
            'occupation'       => $data['occupation'] ?? null,
            'education'        => $data['education'] ?? null,
            'about_me'         => $data['about_me'] ?? null,

            'fcm_token'        => $data['fcm_token'] ?? null,
            'platform'         => $data['platform'] ?? null,
            'token_updated_at' => !empty($data['fcm_token']) ? now() : null,

            'is_active'        => 1,
        ]);

        if (!empty($data['interests']) && is_array($data['interests'])) {
            $user->interests()->sync($data['interests']);
        }

        $user->load('interests:id,name');
        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'message' => 'Account created successfully.',
            'token'   => $token,
            'user'    => $user,
        ], 201);
    }
    */

    // ---------------------------
    // Local helpers for verified token
    // ---------------------------

    private function buildVerifiedTokenLocal(string $purpose, string $phoneE164, int $ttlSeconds): string
    {
        $payload = [
            'v' => 1,
            'purpose' => $purpose,
            'phone_e164' => $phoneE164,
            'exp' => now()->addSeconds($ttlSeconds)->timestamp,
        ];

        return Crypt::encryptString(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function verifyVerifiedTokenLocal(string $token, string $purpose, string $phoneE164): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'invalid_token'];
        }

        if (($payload['purpose'] ?? null) !== $purpose) return ['ok' => false, 'error' => 'wrong_purpose'];
        if ((string) ($payload['phone_e164'] ?? '') !== $phoneE164) return ['ok' => false, 'error' => 'wrong_phone'];
        if ((int) ($payload['exp'] ?? 0) < now()->timestamp) return ['ok' => false, 'error' => 'expired'];

        return ['ok' => true];
    }

    // ---------------------------
    // Phone normalization helpers
    // ---------------------------

    private function normalizeCountryCode(string $countryCode): string
    {
        $cc = preg_replace('/\s+/', '', trim($countryCode));
        if ($cc === '') return $cc;
        return $cc[0] === '+' ? $cc : ('+' . $cc);
    }

    private function normalizePhone(string $phone): string
    {
        $p = preg_replace('/[\s\-]+/', '', trim($phone));
        return ltrim($p, '0');
    }
    
    
    
    
    // updated phone numerb 
    public function sendNewPhoneOtp(Request $request, OtpDeliveryService $otp)
{
    $user = $request->user();
    if (!$user) return response()->json(['message' => 'Unauthenticated.'], 401);
    $data = $request->validate([
        'new_country_code' => ['required', 'string', 'max:6'],
        'new_phone'        => ['required', 'string', 'max:30'],
        'channel'          => ['nullable', 'string', 'in:whatsapp,whatsapp_node,sms'],
    ]);
    $newCc = $this->normalizeCountryCode($data['new_country_code']);
    $newPh = $this->normalizePhone($data['new_phone']);
    $newE164 = $newCc . $newPh;
    // prevent duplicate with another user
    $exists = User::where('country_code', $newCc)
        ->where('phone', $newPh)
        ->where('id', '!=', $user->id)
        ->exists();
    if ($exists) {
        return response()->json(['message' => 'Phone already exists.'], 422);
    }
    $send = $otp->sendOtp('update_phone_new', $newCc, $newPh, $data['channel'] ?? null);
    if (!($send['ok'] ?? false)) {
        return response()->json([
            'message' => 'Failed to send OTP to new phone.',
            'error'   => $send
        ], 502);
    }
    return response()->json([
         'success' => true,
        'message' => 'OTP sent to new phone.',
        'otp_token' => $send['otp_token'],
        'channel' => $send['channel'] ?? null,
        'expires_in' => $otp->ttlSeconds(),
    ], 200);
}
public function confirmNewPhoneWithOtp(Request $request, OtpDeliveryService $otp)
{
    $user = $request->user();
    if (!$user) return response()->json(['message' => 'Unauthenticated.'], 401);
    $data = $request->validate([
        'new_country_code' => ['required', 'string', 'max:6'],
        'new_phone'        => ['required', 'string', 'max:30'],
        'otp_token'        => ['required', 'string'],
        'code'             => ['required', 'digits:6'],
    ]);
    $newCc = $this->normalizeCountryCode($data['new_country_code']);
    $newPh = $this->normalizePhone($data['new_phone']);
    $newE164 = $newCc . $newPh;
    $check = $otp->verifyOtp($data['otp_token'], 'update_phone_new', $newE164, $data['code']);
    if (!($check['ok'] ?? false)) {
        return response()->json([
            'message' => 'Invalid OTP.',
            'error'   => $check['error']
        ], 422);
    }
    $exists = User::where('country_code', $newCc)
        ->where('phone', $newPh)
        ->where('id', '!=', $user->id)
        ->exists();
    if ($exists) {
        return response()->json(['message' => 'Phone already exists.'], 422);
    }
    $user->update([
        'country_code' => $newCc,
        'phone' => $newPh,
    ]);
    return response()->json(['message' => 'Phone updated successfully.'], 200);
}


// update Password 
public function sendPasswordOtp(Request $request, OtpDeliveryService $otp)
{
    $user = $request->user();
    if (! $user) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated.',
            'error' => 'unauthenticated',
        ], 401);
    }

    // Recommended: require current password before sending OTP
    $data = $request->validate([
        'current_password' => ['required', 'string'],
        'channel' => ['nullable', 'string', 'in:whatsapp,whatsapp_node,sms'],
    ]);

    if (!Hash::check($data['current_password'], $user->password)) {
        return response()->json([
            'success' => false,
            'message' => 'Current password is incorrect.',
            'error' => 'current_password_invalid',
        ], 422);
    }

    $cc = $this->normalizeCountryCode((string) $user->country_code);
    $ph = $this->normalizePhone((string) $user->phone);

    $send = $otp->sendOtp('update_password', $cc, $ph, $data['channel'] ?? null);
    if (! ($send['ok'] ?? false)) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to send OTP.',
            'error' => $send['error'] ?? 'otp_send_failed',
        ], 502);
    }

    return response()->json([
        'success' => true,
        'message' => 'OTP sent.',
        'otp_token' => $send['otp_token'],
        'channel' => $send['channel'] ?? null,
        'expires_in' => $send['expires_in'] ?? $otp->ttlSeconds(),
    ], 200);
}

public function updatePasswordWithOtp(Request $request, OtpDeliveryService $otp)
{
    $user = $request->user();
    if (! $user) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated.',
            'error' => 'unauthenticated',
        ], 401);
    }

    $data = $request->validate([
        'otp_token' => ['required', 'string'],
        'code' => ['required', 'digits:6'],
        'new_password' => ['required', 'string', 'min:6', 'confirmed'],
        'new_password_confirmation' => ['required', 'string', 'min:6'],
    ]);

    $phoneE164 = $this->normalizeCountryCode((string) $user->country_code)
        . $this->normalizePhone((string) $user->phone);

    $check = $otp->verifyOtp($data['otp_token'], 'update_password', $phoneE164, $data['code']);
    if (!($check['ok'] ?? false)) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid OTP.',
            'error' => $check['error'] ?? 'invalid_otp',
        ], 422);
    }

    if (Hash::check($data['new_password'], $user->password)) {
        return response()->json([
            'success' => false,
            'message' => 'New password must be different from current password.',
            'error' => 'password_same_as_old',
        ], 422);
    }

    $user->password = Hash::make($data['new_password']);
    $user->save();

    // logout all sessions after password change
    $user->tokens()->delete();

    return response()->json([
        'success' => true,
        'message' => 'Password updated successfully.',
    ], 200);
}
}