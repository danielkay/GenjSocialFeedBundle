<?php

namespace Genj\SocialFeedBundle\Api;

use Facebook\Exceptions\FacebookSDKException;
use Facebook\Facebook;
use Genj\SocialFeedBundle\Entity\Post;

/**
 * Class FacebookApi
 *
 * @package Genj\SocialFeedBundle\Api
 */
class FacebookApi extends SocialApi
{
    protected $providerName = 'facebook';
    protected $fb;
    protected $accessToken;

    /**
     * @param array $oAuthConfig
     * @throws FacebookSDKException
     * @throws \InvalidArgumentException
     */
    public function __construct($oAuthConfig)
    {
        $this->fb = new Facebook([
            'app_id' => $oAuthConfig['facebook']['app_id'],
            'app_secret' => $oAuthConfig['facebook']['app_secret'],
            'default_graph_version' => 'v2.8',
        ]);

        $helper = $this->fb->getRedirectLoginHelper();

        try {
            $accessToken = $helper->getAccessToken();
        } catch(FacebookSDKException $e) {
            // There was an error communicating with Graph
            echo $e->getMessage();
            exit;
        }

        $this->fb->setDefaultAccessToken((string) $accessToken);
    }

    /**
     * @param string $username
     *
     * @return array
     */
    public function getUserPosts($username)
    {
        try {
            $parameters = array('fields' => 'message,link,from,full_picture,created_time,object_id');
            $data = $this->requestGet('/'. $username .'/posts', $parameters);

        } catch (\Exception $ex) {
            echo $ex->getMessage();

            return array();
        }

        return $data->asArray()['data'];
    }

    /**
     * @param \stdClass|array $socialPost
     *
     * @return Post
     */
    protected function getMappedPostObject($socialPost)
    {
        $post = new Post();

        if (!isset($socialPost->message)) {
            return false;
        }

        $post->setProvider($this->providerName);
        $post->setPostId($socialPost->id);

        $parameters = array('fields' => 'username');
        $rawUserDetails = $this->requestGet('/'. $socialPost->from->id, $parameters);

        $userDetails = $rawUserDetails->asArray();

        if (empty($userDetails)) {
            return false;
        }

        $post->setAuthorUsername($userDetails['username']);
        $post->setAuthorName($socialPost->from->name);
        $post->setAuthorFile('https://graph.facebook.com/'. $socialPost->from->id .'/picture');
        $post->setHeadline(strip_tags($socialPost->message));

        $message = $this->getFormattedTextFromPost($socialPost);
        $post->setBody($message);

        if (isset($socialPost->full_picture) && !empty($socialPost->full_picture)) {
            // A picture is set, use the original url as a backup
            $post->setFile($socialPost->full_picture);

            // If there is an object_id, then the original file may be available, so check for that one
            if (isset($socialPost->object_id)) {
                $rawImageDetails = $this->requestGet('/'. $socialPost->object_id, array('fields' => 'images'));
                $imageDetails = $rawImageDetails->asArray();

                if (isset($imageDetails['images'][0]->source)) {
                    $post->setFile($imageDetails['images'][0]->source);
                }
            } else {
                // Check if it is an external image, if so, use the original one.
                $pictureUrlData = parse_url($socialPost->full_picture);
                if (preg_match('#^fbexternal#', $pictureUrlData['host']) === 1) {
                    parse_str($pictureUrlData['query'], $pictureUrlQueryData);
                    if (isset($pictureUrlQueryData['url'])) {
                        $post->setFile($pictureUrlQueryData['url']);
                    }
                }
            }
        }

        $post->setLink('https://www.facebook.com/'. $socialPost->id);

        $post->setPublishAt(new \DateTime($socialPost->created_time));
        $post->setIsActive(true);

        return $post;
    }

    protected function getFormattedTextFromPost($socialPost)
    {
        $text = $socialPost->message;

        // Add href for links prefixed with ***:// (*** is most likely to be http(s) or ftp
        $text = preg_replace("#(^|[\n ])([\w]+?://[\w]+[^ \"\n\r\t< ]*)#", "\\1<a href=\"\\2\" target=\"_blank\">\\2</a>", $text);
        // Add href for links starting with www or ftp
        $text = preg_replace("#(^|[\n ])((www|ftp)\.[^ \"\t\n\r< ]*)#", "\\1<a href=\"http://\\2\" target=\"_blank\">\\2</a>", $text);
        // Add link to hashtags
        $text = preg_replace("/#(\w+)/", "<a href=\"https://www.facebook.com/hashtag/\\1\" target=\"_blank\">#\\1</a>", $text);

        return $text;
    }

    protected function requestGet($method, $parameters = array())
    {
        try {
            $parameterCount = count($parameters);
            if (0 !== $parameterCount) {
                $i = 0;
                foreach ($parameters as $key => $value) {
                    if ($i = 0) {
                        $method .= '?';
                    } elseif ($i !== $parameterCount-1) {
                        $method .= '&';
                    }
                    $method .= "$key=".implode(',', $value);
                    $i++;
                }
            }
            $response = $this->fb->get($method);

            return $response->getGraphNode();
        } catch (\Exception $ex) {
            throw $ex;
        }
    }

}