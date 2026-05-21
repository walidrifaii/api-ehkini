<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\TestNotificationController;
use App\Http\Controllers\Api\V1\FriendshipController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ChatNotificationController;
use App\Http\Controllers\Api\V1\VoiceController;
use App\Http\Controllers\Api\V1\CallNotificationController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\StoryController;
use App\Http\Controllers\Api\V1\GiftController;
use App\Http\Controllers\Api\V1\GiftSendController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\GiftTransactionController;
use App\Http\Controllers\Api\V1\UserSafetyController;
use App\Http\Controllers\Api\V1\TranslationController;
use App\Http\Controllers\Api\V1\LanguageController;
use App\Http\Controllers\Api\V1\AppVersionController;
use App\Models\Language;

// Shared API routes for versioned endpoints.
$v1Controllers = [
    'auth' => \App\Http\Controllers\Api\V1\AuthController::class,
    'user' => \App\Http\Controllers\Api\V1\UserController::class,
    'post' => \App\Http\Controllers\Api\V1\PostController::class,
    'testNotification' => \App\Http\Controllers\Api\V1\TestNotificationController::class,
    'friendship' => \App\Http\Controllers\Api\V1\FriendshipController::class,
    'notification' => \App\Http\Controllers\Api\V1\NotificationController::class,
    'chatNotification' => \App\Http\Controllers\Api\V1\ChatNotificationController::class,
    'voice' => \App\Http\Controllers\Api\V1\VoiceController::class,
    'callNotification' => \App\Http\Controllers\Api\V1\CallNotificationController::class,
    'media' => \App\Http\Controllers\Api\V1\MediaController::class,
    'story' => \App\Http\Controllers\Api\V1\StoryController::class,
    'gift' => \App\Http\Controllers\Api\V1\GiftController::class,
    'giftSend' => \App\Http\Controllers\Api\V1\GiftSendController::class,
    'wallet' => \App\Http\Controllers\Api\V1\WalletController::class,
    'giftTransaction' => \App\Http\Controllers\Api\V1\GiftTransactionController::class,
    'userSafety' => \App\Http\Controllers\Api\V1\UserSafetyController::class,
    'translation' => \App\Http\Controllers\Api\V1\TranslationController::class,
    'language' => \App\Http\Controllers\Api\V1\LanguageController::class,
    'appVersion' => \App\Http\Controllers\Api\V1\AppVersionController::class,
    'page' => \App\Http\Controllers\Api\V1\PageController::class,
    'interest' => \App\Http\Controllers\Api\V1\InterestController::class,
    'agoraToken' => \App\Http\Controllers\Api\V1\AgoraTokenController::class,
];

$v2Controllers = [
    'auth' => \App\Http\Controllers\Api\V2\AuthController::class,
    'user' => \App\Http\Controllers\Api\V2\UserController::class,
    'post' => \App\Http\Controllers\Api\V2\PostController::class,
    'testNotification' => \App\Http\Controllers\Api\V2\TestNotificationController::class,
    'friendship' => \App\Http\Controllers\Api\V2\FriendshipController::class,
    'notification' => \App\Http\Controllers\Api\V2\NotificationController::class,
    'chatNotification' => \App\Http\Controllers\Api\V2\ChatNotificationController::class,
    'voice' => \App\Http\Controllers\Api\V2\VoiceController::class,
    'callNotification' => \App\Http\Controllers\Api\V2\CallNotificationController::class,
    'media' => \App\Http\Controllers\Api\V2\MediaController::class,
    'story' => \App\Http\Controllers\Api\V2\StoryController::class,
    'gift' => \App\Http\Controllers\Api\V2\GiftController::class,
    'giftSend' => \App\Http\Controllers\Api\V2\GiftSendController::class,
    'wallet' => \App\Http\Controllers\Api\V2\WalletController::class,
    'giftTransaction' => \App\Http\Controllers\Api\V2\GiftTransactionController::class,
    'userSafety' => \App\Http\Controllers\Api\V2\UserSafetyController::class,
    'translation' => \App\Http\Controllers\Api\V2\TranslationController::class,
    'language' => \App\Http\Controllers\Api\V2\LanguageController::class,
    'appVersion' => \App\Http\Controllers\Api\V2\AppVersionController::class,
    'page' => \App\Http\Controllers\Api\V2\PageController::class,
    'interest' => \App\Http\Controllers\Api\V2\InterestController::class,
    'agoraToken' => \App\Http\Controllers\Api\V2\AgoraTokenController::class,
];

$versionedApiRoutes = function (array $controllers) {

    /*
    |--------------------------------------------------------------------------
    | Public Auth
    |--------------------------------------------------------------------------
    */
    // Route::post('/register', [AuthController::class, 'register']);
    //   Route::post('/register/send-otp', [AuthController::class, 'registerSendOtp']);
    // Route::post('/register/verify-otp', [AuthController::class, 'registerVerifyOtp']);
    // Route::post('/register', [AuthController::class, 'registerComplete']);
    Route::post('/register', [$controllers['auth'], 'register']);
    
    
    Route::post('/login', [$controllers['auth'], 'login']);
    Route::get('/pages/{slug}', [$controllers['page'], 'show']);
    Route::post('/check-phone', [$controllers['auth'], 'checkPhone']);

  Route::get('/app/version', [$controllers['appVersion'], 'check']);


Route::get('/translations/{lang}', [$controllers['translation'], 'index']);
    /*
    |--------------------------------------------------------------------------
    | Public Data
    |--------------------------------------------------------------------------
    */
    Route::get('/users', [$controllers['user'], 'index']);          // list users
    Route::get('/users/search', [$controllers['friendship'], 'searchUsers']);
    Route::get('/users/{id}', [$controllers['user'], 'showPublic'])->whereNumber('id'); // public user profile by id
    Route::get('/user/{id}', [$controllers['user'], 'showPublic'])->whereNumber('id');  // alias (singular) for mobile clients

    Route::get('/users/{user}/posts', [$controllers['post'], 'userPosts']); // user posts
   Route::post('/test-notification-all', [$controllers['testNotification'], 'pushAll']);
    Route::post('/chat/notify', [$controllers['chatNotification'], 'notify']);
        Route::post('/voice/upload', [$controllers['voice'], 'upload']);
        Route::post('/media/image/upload', [$controllers['media'], 'uploadImage']);
Route::post('/media/video/upload', [$controllers['media'], 'uploadVideo']);

     Route::post('/call/notify', [$controllers['callNotification'], 'notify']);
     Route::post('/call/end', [$controllers['callNotification'], 'end']);
         
         
         Route::get('/interests', [$controllers['interest'], 'index']);
         Route::get('/agora/token', [$controllers['agoraToken'], 'token']);

        Route::get('/dictionary', [$controllers['language'], 'dictionary']);
        Route::get('/languages', [$controllers['language'], 'languages']);

           Route::post('/forgot-password/send-otp', [$controllers['auth'], 'forgotPasswordSendOtp']);
Route::post('/forgot-password/verify-otp', [$controllers['auth'], 'verifyForgotPasswordOtp']);
    Route::post('/forgot-password/reset-password', [$controllers['auth'], 'resetPasswordAfterOtp']);    /* 
    |--------------------------------------------------------------------------
    | Authenticated Routes
    |--------------------------------------------------------------------------
    */
    Route::middleware('auth:sanctum')->group(function () use ($controllers) {

        // User
        Route::get('/me', [$controllers['auth'], 'me']);
        Route::post('/logout', [$controllers['auth'], 'logout']);
        // ✅ Soft delete
        Route::post('/account/deactivate', [$controllers['auth'], 'deactivateAccount']);
        Route::post('/profile/update', [$controllers['auth'], 'updateProfile']);
       
           Route::post('/profile/phone/send-otp-new', [$controllers['auth'], 'sendNewPhoneOtp']);
    Route::post('/profile/phone/confirm-new',  [$controllers['auth'], 'confirmNewPhoneWithOtp']);


Route::post('/profile/password/send-otp', [$controllers['auth'], 'sendPasswordOtp']);
Route::post('/profile/password/update', [$controllers['auth'], 'updatePasswordWithOtp']);
   
        Route::post('/language/change', [$controllers['language'], 'changeLanguage']);
        Route::get('/language/current', [$controllers['language'], 'currentLanguage']);


        Route::post('/posts/{post}/report', [$controllers['post'], 'report']);

        // Posts
        Route::post('/posts', [$controllers['post'], 'store']); // create post (image)
        Route::delete('/posts/{post}', [$controllers['post'], 'destroy']);
        Route::delete('/profile/image', [$controllers['auth'], 'deleteProfileImage']);


 Route::post('/users/block', [$controllers['userSafety'], 'block']);
    Route::post('/users/unblock', [$controllers['userSafety'], 'unblock']);
    Route::post('/users/report', [$controllers['userSafety'], 'report']);
    Route::get('/users/blocked', [$controllers['userSafety'], 'blockedUsers']);
        
        
            Route::post('/friends/request', [$controllers['friendship'], 'sendRequest']);
    Route::post('/friends/respond', [$controllers['friendship'], 'respond']);
    Route::get('/friends/requests', [$controllers['friendship'], 'incomingRequests']);
    Route::post('/friends/requests/cancel', [$controllers['friendship'], 'cancelRequest']);
    Route::post('/friends/remove', [$controllers['friendship'], 'removeFriend']);
    
    
    Route::post('/stories', [$controllers['story'], 'store']); 
        Route::post('/stories/{story}/report', [$controllers['story'], 'report']);
// upload
    Route::get('/stories', [$controllers['story'], 'index']);                 // active stories
    Route::post('/stories/{story}/view', [$controllers['story'], 'view']);    // mark as viewed
    Route::get('/stories/{story}/views', [$controllers['story'], 'views']);   // list viewers
    Route::delete('/stories/{story}', [$controllers['story'], 'destroy']);   // list viewers
    
    
    
    // deleted notification 
    Route::delete('/notifications/{notification}', [$controllers['notification'], 'destroy']);
    
    
        Route::get('/gift-categories', [$controllers['gift'], 'categories']);
    Route::get('/gifts', [$controllers['gift'], 'index']);
    Route::post('/gifts/send', [$controllers['giftSend'], 'send']);
   
        Route::post('/wallet/add', [$controllers['wallet'], 'addBalance']);
        Route::get('/wallet/balance', [$controllers['wallet'], 'balance']);
        Route::get('/wallet/gift-transactions', [$controllers['giftTransaction'], 'index']);



    

    
    
  
    Route::get('/friends', [$controllers['friendship'], 'friends']);
             Route::get('/friends/search', [$controllers['friendship'], 'searchFriends']);
             Route::get('/friends/suggested', [$controllers['friendship'], 'suggestedFriends']);
         


    
    
     Route::get('/friends/{userId}', 
        [$controllers['friendship'], 'friendDetails']
    );
         Route::get('/v1/friends/{userId}', [$controllers['friendship'], 'friendDetails']);

    // Notifications
    Route::get('/notifications', [$controllers['notification'], 'index']);
    Route::post('/notifications/read', [$controllers['notification'], 'markRead']);
    
    Route::post('/test-notification', [$controllers['testNotification'], 'send']);

    });
};

Route::prefix('v1')->group(fn () => $versionedApiRoutes($v1Controllers));
Route::prefix('v2')->group(fn () => $versionedApiRoutes($v2Controllers));
// V2-only: saved user search (last filters) — not exposed on v1.
Route::prefix('v2')->middleware('auth:sanctum')->group(function () {
    Route::get('/users/search/last', [\App\Http\Controllers\Api\V2\FriendshipController::class, 'lastUserSearch']);
    Route::delete('/users/search/last', [\App\Http\Controllers\Api\V2\FriendshipController::class, 'deleteLastUserSearch']);
    Route::post('/users/search/click', [\App\Http\Controllers\Api\V2\FriendshipController::class, 'recordSearchResultClick']);
    Route::delete('/users/search/click', [\App\Http\Controllers\Api\V2\FriendshipController::class, 'deleteSearchResultClick']);
    Route::get('/users/discover-by-country', [\App\Http\Controllers\Api\V2\UserController::class, 'discoverByCountry']);

});
// V2-only OTP registration flow.
Route::prefix('v2')->group(function () use ($v2Controllers) {
    Route::get('/countries', [\App\Http\Controllers\Api\V2\CountryController::class, 'index']);
    Route::post('/register/send-otp', [$v2Controllers['auth'], 'registerSendOtp']);
    Route::post('/register/verify-otp', [$v2Controllers['auth'], 'registerVerifyOtp']);
    Route::post('/register/complete', [$v2Controllers['auth'], 'registerComplete']);
});
