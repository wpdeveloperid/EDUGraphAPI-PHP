<?php
/**
 *  Copyright (c) Microsoft Corporation. All rights reserved. Licensed under the MIT license.
 *  See LICENSE in the project root for license information.
 */

namespace App\Http\Controllers;


use App\Config\SiteConstants;
use App\Services\AADGraphClient;
use App\Services\CookieService;
use App\Services\OrganizationsService;
use App\Services\TokenCacheService;
use App\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Microsoft\Graph\Connect\Constants;
use Socialize;


class O365AuthController extends Controller
{

    /**
     * This function is used for auth and redirect after O365 user login succeed.
     */
    public function oauth()
    {
        $user = Socialite::driver('O365')->user();

        $refreshToken = $user->refreshToken;
        $o365UserId = $user->id;
        $o365Email = $user->email;

        $microsoftTokenArray = (new TokenCacheService())->refreshToken($user->id, $refreshToken, Constants::RESOURCE_ID, true);
        $tokensArray = $this->getTokenArray($user, $microsoftTokenArray);
        (new TokenCacheService)->UpdateOrInsertCache($o365UserId, $refreshToken, $tokensArray);

        $graph = new AADGraphClient;
        $tenant = $graph->GetTenantByToken($microsoftTokenArray['token']);
        $tenantId = $graph->GetTenantId($tenant);
        $orgId = (new OrganizationsService)->CreateOrganization($tenant, $tenantId);
        $this->linkLocalUserToO365IfLogin($user, $o365Email, $o365UserId, $orgId);

        //If user exists on db, check if this user is linked. If linked, go to schools/index page, otherwise go to link page.
        //If user doesn't exists on db, add user information like o365 user id, first name, last name to session and then go to link page.
        $userInDB = User::where('o365UserId', $o365UserId)->first();
        if ($userInDB) {
            if (!$userInDB->isLinked()) {
                return redirect('/link');
            } else {
                Auth::loginUsingId($userInDB->id);
                if (Auth::check()) {
                    return redirect("/schools");
                }
            }
        } else {
            //Below sessions are used for link users and create new local accounts.
            $_SESSION[SiteConstants::Session_OrganizationId] = $orgId;
            $_SESSION[SiteConstants::Session_TenantId] = $tenantId;
            $_SESSION[SiteConstants::Session_Tokens_Array] = $tokensArray;
            $_SESSION[SiteConstants::Session_Refresh_Token] = $refreshToken;
            $_SESSION[SiteConstants::Session_O365_User_ID] = $o365UserId;
            $_SESSION[SiteConstants::Session_O365_User_Email] = $o365Email;
            $_SESSION[SiteConstants::Session_O365_User_First_name] = $user->user['givenName'];
            $_SESSION[SiteConstants::Session_O365_User_Last_name] = $user->user['surname'];
            return redirect('/link');
        }
    }

    /**
     * Return token array and will be insert into tokencache table.
     */
    private function getTokenArray($user, $microsoftTokenArray)
    {
        $ts = $user->accessTokenResponseBody['expires_on'];
        $date = new \DateTime("@$ts");
        $aadTokenExpires = $date->format('Y-m-d H:i:s');
        $format = '{"%s":{"expiresOn":"%s","value":"%s"},"%s":{"expiresOn":"%s","value":"%s"}}';
        return sprintf($format,Constants::AADGraph, $aadTokenExpires, $user->token,Constants::RESOURCE_ID, $microsoftTokenArray['expires'], $microsoftTokenArray['token']);

    }

    /**
     * If a local user is login, link O365 user with local user.
     * @param $user
     * @param $o365Email
     * @param $o365UserId
     * @param $orgId
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    private function linkLocalUserToO365IfLogin($user, $o365Email, $o365UserId, $orgId)
    {
        if (Auth::check()) {

            //A local user must link to and o365 account that is not linked.
            if (User::where('o365Email', $o365Email)->first())
                return back()->with('msg', 'Failed to link accounts. The Office 365 account ' . $o365Email . ' is already linked to another local account.');

            $localUser = Auth::user();
            $localUser->o365UserId = $o365UserId;
            $localUser->o365Email = $o365Email;
            $localUser->firstName = $user->user['givenName'];
            $localUser->lastName = $user->user['surname'];
            $localUser->password = '';
            $localUser->OrganizationId = $orgId;
            $localUser->save();
            return redirect("/schools");
        }
    }

    /**
     * If an O365 user is linked and login to the site, after logout, go to this page directly for quick login.
     */
    public function o365LoginHint()
    {
        $cookieServices = new CookieService();
        $email = $cookieServices->GetCookiesOfEmail();
        $userName = $cookieServices->GetCookiesOfUsername();
        $data = ["email" => $email, "userName" => $userName];
        return view('auth.o365loginhint', $data);

    }

    public function o365Login()
    {
        return Socialize::with('O365')->redirect();
    }

    /**
     * This function is for O365 login hint page after a user clicks 'Login with a different account'. It will clean all cookies and then goes to login page.
     */
    public function differentAccountLogin()
    {
        $cookieServices = new CookieService();
        $cookieServices->ClearCookies();
        return redirect('/login');
    }

}