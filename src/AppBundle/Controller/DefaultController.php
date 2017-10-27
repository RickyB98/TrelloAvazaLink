<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\CssSelector\Exception\InternalErrorException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class DefaultController extends Controller
{
    /**
     * @Route("/oauth", name="oauth")
     * @param Request $request
     * @return JsonResponse
     */
    public function indexAction(Request $request)
    {
        $code = $request->get('code');
        $accId = $request->get('accountid');

        $curl = curl_init("https://any.avaza.com/oauth2/token");
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query([
            'code' => $code,
            'grant_type' => 'authorization_code',
            'client_id' => $this->getParameter('avaza')['id'],
            'client_secret' => $this->getParameter('avaza')['secret'],
            'redirect_uri' => $this->generateUrl("oauth", [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]));

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($curl);

        curl_close($curl);

        $json = json_decode($result, true);

        $json['accId'] = $accId;

        $fp = fopen("../app/config/avaza.json", "w");
        fwrite($fp, json_encode($json));
        fclose($fp);

        return new JsonResponse(['status' => 'All good.'], 200);
    }


    /**
     * @Route("/trello", name="trello")
     * @param Request $request
     * @return Response
     */
    public function trelloWebhook(Request $request) {
        // todo: verify IP

        $body = file_get_contents('php://input');
        $input = json_decode($body, true);

        $action = $input['action']['type'];
        $acceptedActions = [
            'addMemberToCard',
            'createCard',
        ];
        if (!in_array($action, $acceptedActions)) return new Response("OK", 200);

        $boardId = $input['action']['data']['board']['id'];
        $cardId = $input['action']['data']['card']['id'];

        if (!isset($this->getParameter("mapping")[$boardId])) return new Response("OK", 200);

        $curl = curl_init("https://api.trello.com/1/cards/".$cardId."?key=".$this->getParameter("trello")['key']."&token=".$this->getParameter("trello")['token']);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $card = json_decode(curl_exec($curl), true);

        curl_close($curl);

        if (!in_array($this->getParameter("trello")['userId'], $card['idMembers'])) return new Response("OK", 200);

        if (!file_exists("../app/config/avaza.json")) throw new InternalErrorException("No config found.", 500);
        $json = json_decode(file_get_contents("../app/config/avaza.json"), true);
        if (!isset($json['access_token'])) {
            $json = $this->refresh($json);
        }
        if (!isset($json['access_token'])) {
            throw new InternalErrorException("Couldn't authenticate.", 500);
        }

        if (!$this->addTask($card, $json['access_token'])) {
            $json = $this->refresh($json);
            if (!$this->addTask($card, $json['access_token'])) {
                throw new InternalErrorException("Couldn't authenticate.", 500);
            }
        }

        return new Response("OK", 200);
    }


    private function addTask($card, $accessToken) {
        $curl = curl_init("https://any.avaza.com/");
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            "Content-Type: application/x-www-form-urlencoded",
            "Accept: text/json",
            "Authorization: Bearer ".$accessToken,
        ]);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query([
            'ProjectIDFK' => $this->getParameter("avaza")['projectId'],
            'SectionIDFK' => $this->getParameter("mapping")[$card['idBoard']],
            'Title' => $card['name'],
            'Description' => $card['desc']."\nImported from Trello. ".$card['shortUrl'],
            'AssignedToUserIDFK' => $this->getParameter("avaza")['userId'],
        ]));

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_exec($curl);

        $response = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($response !== 200) return false;

        return true;
    }

    private function refresh($json) {
        if (isset($json['refresh_token']) && isset($json['accId'])) {
            $curl = curl_init("https://any.avaza.com/oauth2/token");
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query([
                'code' => $json['refresh_token'],
                'grant_type' => 'refresh_token',
                'client_id' => $this->getParameter('avaza')['id'],
                'client_secret' => $this->getParameter('avaza')['secret'],
                'redirect_uri' => $this->generateUrl("oauth", [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]));

            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $result = json_decode(curl_exec($curl), true);

            curl_close($curl);

            $result['accId'] = $json['accId'];

            $fp = fopen("../app/config/avaza.json", "w");
            fwrite($fp, json_encode($result));
            fclose($fp);
            return $result;
        } else {
            return null;
        }
    }

    /**
     * @Route("/authorize", name="authorize")
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     * @internal param Request $request
     */
    public function redirectAuthorize() {
        return $this->redirect("https://any.avaza.com/oauth2/authorize?response_type=code&client_id=".$this->getParameter('avaza')['id']."&redirect_uri=".$this->generateUrl("oauth", [], UrlGeneratorInterface::ABSOLUTE_URL)."&scope=read,write");
    }
}
