<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2019, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace LetsConnect\Portal;

use fkooman\SeCookie\SessionInterface;
use LetsConnect\Common\Http\RedirectResponse;
use LetsConnect\Common\Http\Request;
use LetsConnect\Common\Http\Service;
use LetsConnect\Common\Http\ServiceModuleInterface;

class LogoutModule implements ServiceModuleInterface
{
    /** @var \fkooman\SeCookie\SessionInterface */
    private $session;

    /** @var string|null */
    private $logoutUrl;

    /** @var string */
    private $returnParameter;

    /**
     * @param \fkooman\SeCookie\SessionInterface $session
     * @param string|null                        $logoutUrl
     * @param string                             $returnParameter
     */
    public function __construct(SessionInterface $session, $logoutUrl, $returnParameter)
    {
        $this->session = $session;
        $this->logoutUrl = $logoutUrl;
        $this->returnParameter = $returnParameter;
    }

    /**
     * @param \LetsConnect\Common\Http\Service $service
     *
     * @return void
     */
    public function init(Service $service)
    {
        // new URL since we introduce SAML / Mellon logout
        $service->post(
            '/_logout',
            /**
             * @return \LetsConnect\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                $httpReferrer = $request->requireHeader('HTTP_REFERER');
                if (null !== $this->logoutUrl) {
                    // we can't destroy the complete session here, we need to
                    // delete the keys one by one as some may be used by e.g.
                    // the SAML authentication backend...
                    $this->session->delete('_last_authenticated_at_ping_sent');
                    $this->session->delete('_saml_auth_time');
                    $this->session->delete('_two_factor_verified');
                    $this->session->delete('_mellon_auth_user');
                    $this->session->delete('_mellon_auth_time');
                    $this->session->delete('_two_factor_enroll_redirect_to');
                    $this->session->delete('_two_factor_verified');
                    $this->session->delete('_form_auth_user');
                    $this->session->delete('_form_auth_permission_list');
                    $this->session->delete('_form_auth_time');

                    // a logout URL is defined, this is used by SAML/Mellon
                    return new RedirectResponse(
                        sprintf(
                            '%s?%s',
                            $this->logoutUrl,
                            http_build_query(
                                [
                                    $this->returnParameter => $httpReferrer,
                                ]
                            )
                        )
                    );
                }

                $this->session->destroy();

                return new RedirectResponse($httpReferrer);
            }
        );
    }
}