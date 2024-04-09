<?php
 
namespace App\Http\Controllers;
 
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Request;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use App\Http\Controllers\Controller;
use App\Http\Controllers\SendMailController;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Support\Facades\Auth;
// use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Token;
use App\Models\User;
use App\Models\UserRight;
use App\Models\DeviceLoginToken;
use App\Models\PaymentProcess;
use App\Helpers\PlanHelper;
use JWTAuth;
use Validator;
use DB;
use Log;
use Helper;
use Exception;
use Illuminate\Support\Str; 

class JwtAuthController extends Controller
{
    public $token = true;

    /**
     * Where to redirect users after verification.
     *
     * @var string
     */
    protected $redirectTo = '/';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        //$this->middleware('auth');
    }

    public function register(Request $request)
    {
        $error_key =  Str::random(16);
        Log::info(["API => JwtAuthController => register", "error_id" => $error_key]);
        try {
            DB::beginTransaction();
            $validator = Validator::make($request->all(), [
                'name' => 'string|max:255',
                'email' => 'required|string|max:255|unique:users',
                'password' => 'required|string|min:6',
                'user_type' => 'required',
            ], [
                'email.required' => trans('validation.Please_provide_your_email'),
                'email.email_with_domain' => trans('validation.Please_enter_a_valid_email_address'),
                'email.unique' => trans('validation.This_email_is_already_taken'),
                'password.required' => trans('validation.Please_provide_your_password'),
            ]);

            if ($validator->fails()) {
                $result = json_decode($validator->errors(), true);
                $message = '';
                foreach ($result as $value) {
                    $message = implode(', ', $value);
                    break;
                }
                DB::rollback();
                return response()->json(['status' => 400, 'message' => $message, 'result' => null], 400);
            }

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($request->password),
                'is_profile_filled' => $request->user_type === config('constant.user_type.admin') ? config('constant.status.yes') : config('constant.status.no'),
                'user_type_id' => $request->user_type,
                'user_status_id' => config('constant.user_status.not_verified'),
                'notification_status_id' => config('constant.notification_status.on'),
                'default_language_id' => config('constant.language.english'),
            ]);

            DB::commit();
            event(new Registered($user));
            return response()->json([
                'status' => 200,
                'message' => trans('validation.account_active'),
                'result' => null
            ]);
        } catch (Exception $e) {
            DB::rollback();
            Log::error(["error" => $e->getMessage(), 'error_id' => $error_key]);
            return response()->json(['status' => 500, 'message' => trans('validation.something_went_wrong'), 'error_id' => $error_key, 'result' => null], 500);
        }
    }

    /** User Login */
    public function login(Request $request)
    {
        $error_key =  Str::random(16);
        Log::info(["API => JwtAuthController => login", "error_id" => $error_key]);
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|string|email_with_domain',
                'password' => 'required|string',
                'device_type' => 'in:1,2,3',
                // 'device_id' => 'required|string',
            ], [
                'email.required' => trans('validation.Please_enter_email_id'),
                'email.email_with_domain' => trans('validation.Please_enter_a_valid_email_address'),
                'password.required' => trans('validation.Please_enter_password'),
                'device_type.required' => trans('validation.Please_enter_device_type'),
                'device_type.in' => trans('validation.Please_enter_valid_device_type'),
                // 'device_id.required' => trans('validation.Please_enter_device_id'),
                // 'device_id.string' => trans('validation.Please_enter_device_id'),
            ]);
    
            if ($validator->fails()) {
                $result = json_decode($validator->errors(), true);
                $message = '';
                foreach ($result as $value) {
                    $message = implode(', ', $value);
                    break;
                }
                return response()->json(['status' => 400, 'message' => $message, 'result' => null], 400);
            }
    
            $input = $request->only('email', 'password');
            $jwt_token = null;

            // $user_email = User::where('email', $request->email)->where('user_status_id' , '!=' , config('constant.user_status.inactive'))->first();
            $user_email = User::where('email', $request->email)->first();
            /** Here, we check this email id is exists or not */
            if($user_email == null){
                return response()->json([
                    'status' => 400,
                    'message' => trans('validation.The_given_email_is_does_not_exist'),
                    'result' => null
                ], 400);
            }

            if($user_email->user_status_id == config('constant.user_status.not_verified')){
                return response()->json([
                    'status' => 401,
                    'message' => trans('validation.This_user_email_is_not_verified'),
                    'result' => null
                ], 401);
            }

            /** Here, we check user active or suspend */
            if($user_email->user_status_id == config('constant.user_status.inactive')){
                return response()->json([
                    'status' => 401,
                    'message' => trans('validation.This_user_profile_is_suspended'),
                    'result' => null
                ], 401);
            }

            /** Here, we check user deleted or not */
            if($user_email->deleted_at !== NULL){
                return response()->json([
                    'status' => 401,
                    'message' => trans('validation.This_user_profile_is_deleted'),
                    'result' => null
                ], 401);
            }
    
            if (!$jwt_token = JWTAuth::attempt($input)) {
                return response()->json([
                    'status' => Response::HTTP_UNAUTHORIZED,
                    'message' => trans('validation.invalid_login'),
                    'result' => null
                ], Response::HTTP_UNAUTHORIZED);
            }

            $user = User::where('email', $request->email)->whereNotNull('email_verified_at')->select(
                'email',
                'name',
                'is_profile_filled',
                'user_type_id as user_type',
                'user_status_id as status',
                'default_language_id as default_language'
            )->first();

            /** We have given the user rights for the staff user only */
            $user_rights = UserRight::with('module')->where('user_id', $user_email->id)->where('deleted_at', NULL)->get()->toArray();
            $processedStaffData = [];
            foreach($user_rights as $rights){
                $staffUserDetails = [];
                $staffUserDetails = [
                    "module_id" => $rights['module_id'], 
                    "is_view" => $rights['is_view'],
                    "is_edit" => $rights['is_edit'],
                    "module_name" => $rights['module']['name']
                ];
                $processedStaffData[] = $staffUserDetails;
            }
            $user['userrights'] = !empty($processedStaffData) ? $processedStaffData : array(); //user rights data

            /**Create request and insert data to the device token table */
            if(!empty($request->device_fcm_token)){
                DB::beginTransaction();
                $requestData = [
                    'user_id' => $user_email['id'],
                    'fcm_token' => $request->device_fcm_token,
                    'device_type' => $request->device_type,
                    'device_id' => $request->device_id
                ];

                $device_id_exist = DeviceLoginToken::where('fcm_token', $request->device_fcm_token)->first();

                //device fcm not exist then create
                if(!$device_id_exist){
                    $sepdata = DeviceLoginToken::create($requestData); 
                }else{
                    $updateFcm = [
                        'user_id' => $user_email['id'],
                        'device_type' => $request->device_type ?? NULL,
                        'device_id' => $request->device_id ?? NULL,
                    ];
                    $sepdata = DeviceLoginToken::where('fcm_token', $request->device_fcm_token)->update($updateFcm); 
                }
                DB::commit();
            }

            $user['user_plan_rights'] = array();
            $user['user_pending_payment'] = NULL;
            $getDetails = array();

            //user plans ,payment status and plan rights based on user role
            if($user_email['user_type_id'] == config('constant.user_type.company_admin')){
                $getPlanRights = PlanHelper::getUserPlanRights($user_email['id']);
                if(!empty($getPlanRights)){
                    $user['user_plan_rights'] = $getPlanRights;
                }                
                $getDetails = PaymentProcess::where("company_user_id",$user_email['id'])->first();
                if(!empty($getDetails)){
                    $getDetails = [];
                    $getDetails = [
                        "in_progress" => true
                    ];
                    $user['user_pending_payment'] = $getDetails;
                }

            }elseif ($user_email['user_type_id'] == config('constant.user_type.company_staff')) {
                $getPlanRights = PlanHelper::getUserPlanRights($user_email['staff_created_by']);
                if(!empty($getPlanRights)){
                    $user['user_plan_rights'] = $getPlanRights;
                }
            }elseif ($user_email['user_type_id'] == config('constant.user_type.candidate')) {
                $getDetails = PaymentProcess::where("company_user_id",$user_email['id'])->first();
                if(!empty($getDetails)){
                    $getDetails = [];
                    $getDetails = [
                        "in_progress" => true
                    ];
                    $user['user_pending_payment'] = $getDetails;
                }
            }

            return response()->json([
                'status' => 200,
                'message' => trans('validation.login_successfully'),
                'result' => [
                    'token' => $jwt_token,
                    'userdata' => $user
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error(["error" => $e->getMessage(), 'error_id' => $error_key]);
            return response()->json(['status' => 500, 'message' => trans('validation.something_went_wrong'), 'error_id' => $error_key, 'result' => null], 500);
        }
    }

    /** User Logout */
    public function logout(Request $request){
        $token = JWTAuth::getToken(); // Retrieve the token from the request headers or other location
        
        if (!$token) {
            return response()->json([
                'status' => Response::HTTP_UNAUTHORIZED,
                'message' => trans('validation.Token_is_required'),
            ], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $validator = Validator::make($request->all(), [
                'device_type' => 'in:1,2,3',
                // 'device_id' => 'required|string',
            ], [
                'device_type.required' => trans('validation.Please_enter_device_type'),
                'device_type.in' => trans('validation.Please_enter_valid_device_type'),
                // 'device_id.required' => trans('validation.Please_enter_device_id'),
                // 'device_id.string' => trans('validation.Please_enter_device_id'),
            ]);
    
            if ($validator->fails()) {
                $result = json_decode($validator->errors(), true);
                $message = '';
                foreach ($result as $value) {
                    $message = implode(', ', $value);
                    break;
                }
                return response()->json(['status' => 400, 'message' => $message, 'result' => null], 400);
            }
    
            if ($validator->fails()) {
                $result = json_decode($validator->errors(), true);
                $message = '';
                foreach ($result as $value) {
                    $message = implode(', ', $value);
                    break;
                }
                return response()->json(['status' => 400, 'message' => $message, 'result' => null], 400);
            }

            JWTAuth::invalidate($token); // Invalidate the provided token
            if(!empty($request->device_fcm_token)){
                if($request->device_type == 1){
                    //in web remove fcm token based on fcm token
                    DeviceLoginToken::where('fcm_token', $request->device_fcm_token)->delete();
                }else{
                    //in mobile and ios remove fcm token based on device id
                    DeviceLoginToken::where('device_id', $request->device_id)->delete();
                }
            }

            return response()->json([
                'status' => Response::HTTP_OK,
                'message' => trans('validation.logout_successfully'),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => Response::HTTP_INTERNAL_SERVER_ERROR,
                'message' => trans('validation.internal_server_error'),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** User change FCM */
    public function UpdateFcm(Request $request){
        $error_key =  Str::random(16);
        Log::info(["API => JwtAuthController => UpdateFcm", "error_id" => $error_key]);
        try {
            $token = JWTAuth::getToken(); // Retrieve the token from the request headers or other location
        
            if (!$token) {
                return response()->json([
                    'status' => Response::HTTP_UNAUTHORIZED,
                    'message' => trans('validation.Token_is_required'),
                ], Response::HTTP_UNAUTHORIZED);
            }

            $user_id = Auth::id();

            $validator = Validator::make($request->all(), [
                'device_type' => 'required|in:1,2,3',
                'device_id' => 'required|string',
                'device_fcm_token' => 'required',
            ], [
                'device_type.required' => trans('validation.Please_enter_device_type'),
                'device_type.in' => trans('validation.Please_enter_valid_device_type'),
                'device_id.required' => trans('validation.Please_enter_device_id'),
                'device_id.string' => trans('validation.Please_enter_device_id'),
                'device_fcm_token.required' => trans('validation.Please_enter_device_fcm_token'),
            ]);
    
            if ($validator->fails()) {
                $result = json_decode($validator->errors(), true);
                $message = '';
                foreach ($result as $value) {
                    $message = implode(', ', $value);
                    break;
                }
                return response()->json(['status' => 400, 'message' => $message, 'result' => null], 400);
            }
    
            if ($validator->fails()) {
                $result = json_decode($validator->errors(), true);
                $message = '';
                foreach ($result as $value) {
                    $message = implode(', ', $value);
                    break;
                }
                return response()->json(['status' => 400, 'message' => $message, 'result' => null], 400);
            }
            
            DB::beginTransaction();
            if($request->device_type == 1){
                $oldFcm = $request->device_fcm_token_old ?? NULL;
                if(!empty($oldFcm)){
                    $device_id_exist = DeviceLoginToken::where("fcm_token", $oldFcm)->first();

                    //device fcm not exist then create
                    if(!$device_id_exist){
                        $requestData = [
                            'user_id' => $user_id,
                            'fcm_token' => $request->device_fcm_token,
                            'device_type' => $request->device_type,
                            'device_id' => $request->device_id
                        ];
                        $sepdata = DeviceLoginToken::create($requestData); 
                    }else{
                        $updateFcm = [
                            'user_id' => $user_id,
                            'device_type' => $request->device_type ?? NULL,
                            'device_id' => $request->device_id ?? NULL,
                        ];
                        $sepdata = DeviceLoginToken::where('fcm_token', $oldFcm)->update($updateFcm); 
                    }
                }else{
                    $device_id_exist = DeviceLoginToken::where('fcm_token', $request->device_fcm_token)->first();

                    //device fcm not exist then create
                    if(!$device_id_exist){
                        $requestData = [
                            'user_id' => $user_id,
                            'fcm_token' => $request->device_fcm_token,
                            'device_type' => $request->device_type,
                            'device_id' => $request->device_id
                        ];
                        $sepdata = DeviceLoginToken::create($requestData); 
                    }else{
                        $updateFcm = [
                            'user_id' => $user_id,
                            'device_type' => $request->device_type ?? NULL,
                            'device_id' => $request->device_id ?? NULL,
                        ];
                        $sepdata = DeviceLoginToken::where('fcm_token', $request->device_fcm_token)->update($updateFcm); 
                    }
                }
            }else{
                $device_id_exist = DeviceLoginToken::where("device_id", $request->device_id)->first();
                if(!$device_id_exist){
                    DB::rollback();
                    return response()->json(['status' => 400, 'message' => trans('validation.The_given_device_id_doest_not_exist'), 'result' => null], 400);
                }
                //update device tabel fcm token based on device id
                $user = DeviceLoginToken::where("device_id", $request->device_id)->update([
                    'fcm_token' => $request->device_fcm_token,
                ]);
            }

            DB::commit();

            $responseData = ['status' => 200, 'message' =>trans('validation.Device_fcm_token_updated_successfully'), 'result' => null];
            return response()->json($responseData, 200);
        } catch (\Exception $e) {
            Log::error(["error" => $e->getMessage(), 'error_id' => $error_key]);
            return response()->json(['status' => 500, 'message' => trans('validation.something_went_wrong'), 'error_id' => $error_key, 'result' => null], 500);
        }
    }

    public function getUser(Request $request)
    {
        $this->validate($request, [
            'token' => 'required'
        ]);

        $user = JWTAuth::authenticate($request->token);

        return response()->json([
            'status' => 200,
            'message' => trans('validation.logout_successfully'),
            'result' => $user
        ]);
    }

    public function verifyEmail(Request $request)
    {
        try {
            $ua = strtolower($_SERVER['HTTP_USER_AGENT']);
            if (stripos($ua, 'android') !== false && stripos($ua, 'mobile') !== false) {
                $post = $request->all();
                if (!empty($post) && $request->m !== true) {
                    $full_url = url()->full();
                    $url = stripslashes($full_url);
                    $afterQ = explode('?',$url);
                    $beforeQ = explode('/',$afterQ[0]);
                    unset($beforeQ[0]);
                    unset($beforeQ[1]);
                    array_pop($beforeQ);
                    $before_url = implode('/', $beforeQ);
                    $redirect_url = $full_url."?m=true";
                    return redirect('intent://'.$before_url.'?url='.$full_url.'#Intent;scheme=https;package=com.jobaroot.android;S.browser_fallback_url='.$redirect_url.';end')->with(['verified'=> true, 'status' => trans('validation.account_verified') ]);
                }
            }

            $token = new Token($request->route('token'));
            $payload = JWTAuth::decode($token)->getClaims()->toPlainArray();
            $user = User::where("email", $payload['sub'])->first();
            
            if ($user) {
                if ($user->email_verified_at && $user->user_status_id === config('constant.user_status.active')) {
                    if (preg_match('/^[\p{Latin} ]+$/u', $user->name)) {
                        return response()->json([
                            'status' => 400,
                            'message' => trans('validation.account_already_verified', [], "en"),
                            'result' => null
                        ], 400);
                    } else {
                        return response()->json([
                            'status' => 400,
                            'message' => trans('validation.account_already_verified', [], "ar"),
                            'result' => null
                        ], 400);
                    }
                } else {
                    $name = strval($user->name);
                    $user->markEmailAsVerified();
                    $user = User::where("email", $payload['sub'])->update([
                        "user_status_id" => config('constant.user_status.active')
                    ]);
                    if(preg_match('/^[\p{Latin} ]+$/u', $name)) {
                        return response()->json([
                            'status' => 200,
                            'message' => trans('validation.account_verified_successfully', [], "en"),
                            'result' => $user
                        ]);
                    } else {
                        return response()->json([
                            'status' => 200,
                            'message' => trans('validation.account_verified_successfully', [], "ar"),
                            'result' => $user
                        ]);
                    }
                }
            } else {
                return response()->json([
                    'status' => 400,
                    'message' => trans('validation.cannot_find_account'),
                    'result' => null
                ], 400);
            }
        } catch (JWTException $e) {
            if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenExpiredException) {
                return response()->json([
                    'status' => 400,
                    'message' => trans('validation.activation_link_expired'),
                    'result' => null
                ], 400);
            } else if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenInvalidException) {
                return response()->json([
                    'status' => 400,
                    'message' => trans('validation.invalid_activation_link'),
                    'result' => null
                ], 400);
            } else {
                return response()->json([
                    'status' => 500,
                    'message' => trans('validation.something_went_wrong'),
                    'result' => null
                ], 500);
            }
        }
    }

    /** verify staff user with password and email token */
    public function verifyStaffUser(Request $request)
    {
        try {
            // $ua = strtolower($_SERVER['HTTP_USER_AGENT']);
            // if (stripos($ua, 'android') !== false && stripos($ua, 'mobile') !== false) {
            //     $post = $request->all();
            //     if (!empty($post) && $request->m !== true) {
            //         $full_url = url()->full();
            //         $url = stripslashes($full_url);
            //         $afterQ = explode('?',$url);
            //         $beforeQ = explode('/',$afterQ[0]);
            //         unset($beforeQ[0]);
            //         unset($beforeQ[1]);
            //         array_pop($beforeQ);
            //         $before_url = implode('/', $beforeQ);
            //         $redirect_url = $full_url."?m=true";
            //         return redirect('intent://'.$before_url.'?url='.$full_url.'#Intent;scheme=https;package=com.jobaroot.android;S.browser_fallback_url='.$redirect_url.';end')->with(['verified'=> true, 'status' => trans('validation.account_verified') ]);
            //     }
            // }

            $token = new Token($request->route('token'));
            $payload = JWTAuth::decode($token)->getClaims()->toPlainArray();

            $user = User::where("email", $payload['sub'])->first();

            $validator = Validator::make($request->all(), [
                'password' => 'required|string|min:6|confirmed',
            ], [
                'password.required' => trans('validation.Please_provide_your_password'),
                'password.confirmed' => trans('validation.Passwords_do_not_match'),
            ]);

            if ($validator->fails()) {
                $result = json_decode($validator->errors(), true);
                $message = '';
                foreach ($result as $value) {
                    $message = implode(', ', $value);
                    break;
                }
                return response()->json(['status' => 400, 'message' => $message, 'result' => null], 400);
            }
            
            if ($user) {
                if ($user->email_verified_at && $user->user_status_id === config('constant.user_status.active')) {
                    if (preg_match('/^[\p{Latin} ]+$/u', $user->name)) {
                        return response()->json([
                            'status' => 400,
                            'message' => trans('validation.account_already_verified', [], "en"),
                            'result' => null
                        ], 400);
                    } else {
                        return response()->json([
                            'status' => 400,
                            'message' => trans('validation.account_already_verified', [], "ar"),
                            'result' => null
                        ], 400);
                    }
                } else {
                    $name = strval($user->name);
                    $user->markEmailAsVerified();
                    $user = User::where("email", $payload['sub'])->update([
                        "user_status_id" => config('constant.user_status.active'),
                        "password" => bcrypt($request->password),
                    ]);
                    if(preg_match('/^[\p{Latin} ]+$/u', $name)) {
                        return response()->json([
                            'status' => 200,
                            'message' => trans('validation.account_verified_successfully', [], "en"),
                            'result' => $user
                        ]);
                    } else {
                        return response()->json([
                            'status' => 200,
                            'message' => trans('validation.account_verified_successfully', [], "ar"),
                            'result' => $user
                        ]);
                    }
                }
            } else {
                return response()->json([
                    'status' => 400,
                    'message' => trans('validation.cannot_find_account'),
                    'result' => null
                ], 400);
            }
        } catch (JWTException $e) {
            if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenExpiredException) {
                return response()->json([
                    'status' => 400,
                    'message' => trans('validation.activation_link_expired'),
                    'result' => null
                ], 400);
            } else if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenInvalidException) {
                return response()->json([
                    'status' => 400,
                    'message' => trans('validation.invalid_activation_link'),
                    'result' => null
                ], 400);
            } else {
                return response()->json([
                    'status' => 500,
                    'message' => trans('validation.something_went_wrong'),
                    'result' => null
                ], 500);
            }
        }
    }

    /**
     * Resend the email verification notification.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function resendVerificationLink(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email_with_domain',
        ], [
            'email.required' => trans('validation.Please_provide_your_email'),
            'email.email_with_domain' => trans('validation.Please_enter_a_valid_email_address'),
        ]);

        if ($validator->fails()) {
            $result = json_decode($validator->errors(), true);
            $message = '';
            foreach ($result as $value) {
                $message = implode(', ', $value);
                break;
            }
            return response()->json(['status' => 400, 'message' => $message, 'result' => null], 400);
        }

        $user = User::where('email', $request->email)->where('deleted_at', null)->first();

        if ($user) {
            if ($user->email_verified_at || $user->user_status_id === config('constant.user_status.active')) {
                return response()->json([
                    'status' => 400,
                    'message' => trans('validation.account_already_verified'),
                    'result' => null
                ], 400);
            }
            $user->sendEmailVerificationNotification();
            return response()->json([
                'status' => 200,
                'message' => trans('validation.account_active'),
                'result' => null
            ], 200);
        } else{
            return response()->json([
                'status' => 400,
                'message' => trans('validation.cannot_find_account'),
                'result' => null
            ], 400);
        }
    }
}