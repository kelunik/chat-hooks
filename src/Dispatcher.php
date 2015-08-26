<?php

namespace Kelunik\Chat\Integration;

use Aerys\Request;
use Aerys\Response;
use Amp\Artax\Client;
use Amp\Artax\Request as HttpRequest;
use Auryn\Injector;
use Auryn\InjectorException;
use Exception;
use JsonSchema\Uri\UriRetriever;
use JsonSchema\Validator;
use RuntimeException;
use stdClass;

class Dispatcher {
    /**
     * @var Client
     */
    private $http;

    /**
     * @var UriRetriever
     */
    private $retriever;

    /**
     * @var HookRepository
     */
    private $hookRepository;

    /**
     * @var Service[]
     */
    private $services;

    /**
     * @var stdClass[]
     */
    private $schemas;

    /**
     * @var Validator
     */
    private $validator;

    /**
     * @var array
     */
    private $config;

    public function __construct(Client $http, UriRetriever $retriever, Validator $validator, HookRepository $hookRepository, array $config) {
        $this->http = $http;
        $this->retriever = $retriever;
        $this->validator = $validator;
        $this->hookRepository = $hookRepository;
        $this->config = $config;
        $this->services = [];
        $this->initialize();
    }

    /**
     * Creates all service hook handlers.
     */
    private function initialize() {
        $injector = new Injector;

        $namespace = __NAMESPACE__ . "\\Service\\";
        $basePath = __DIR__ . "/../vendor/kelunik/chat-services/res/schema/";

        $transform = function ($match) {
            return strtoupper($match[1]);
        };

        try {
            foreach ($this->config["services"] as $name => $enabled) {
                if (!$enabled) {
                    continue;
                }

                $service = $injector->make($namespace . preg_replace_callback("~-([a-z])~", $transform, ucfirst($name)));
                $found = false;

                foreach (scandir($basePath . $name) as $file) {
                    if ($file === "." || $file === "..") {
                        continue;
                    }

                    $found = true;
                    $uri = realpath($basePath . $name . "/" . $file);

                    $schema = $this->retriever->retrieve("file://" . $uri);
                    $this->schemas[$name][strtok($file, ".")] = $schema;
                }

                if (!$found) {
                    throw new RuntimeException("every service must have at least one event schema");
                }

                $this->services[$name] = $service;
            }
        } catch (InjectorException $e) {
            throw new RuntimeException("couldn't create all services", 0, $e);
        }
    }

    /**
     * Handles all hooks.
     *
     * @param Request  $request HTTP request
     * @param Response $response HTTP response
     * @param array    $args URL args
     */
    public function handle(Request $request, Response $response, array $args) {
        $response->setHeader("content-type", "text/plain");

        $token = $request->getQueryVars()["token"] ?? "";

        if (!$token || !is_string($token)) {
            $response->setStatus(401);
            $response->send("Failure: No token was provided.");

            return;
        }

        // use @ so we don't have to check for invalid strings manually
        $token = (string) @hex2bin($token);

        $hook = yield $this->hookRepository->get($args["id"]);

        if (!$hook) {
            $response->setStatus(404);
            $response->send("Failure: Hook does not exist.");

            return;
        }

        if (!hash_equals($hook->token, $token)) {
            $response->setStatus(403);
            $response->send("Failure: Provided token doesn't match.");

            return;
        }

        $name = $args["service"];

        if (!isset($this->services[$name])) {
            $response->setStatus(404);
            $response->send("Failure: Unknown service.");

            return;
        }

        $contentType = strtok($request->getHeader("content-type"), ";");
        $body = yield $request->getBody();

        switch ($contentType) {
            case "application/json":
                $payload = json_decode($body);

                break;

            case "application/x-www-form-urlencoded":
                parse_str($body, $payload);
                $payload = json_decode(json_encode($payload));

                break;

            default:
                $response->setStatus(415);
                $response->send("Failure: Content-type not supported.");

                return;
        }

        $service = $this->services[$name];
        $headers = $request->getAllHeaders();
        $event = $service->getEventName($headers, $payload);

        if (!isset($this->schemas[$name][$event])) {
            $response->setStatus(400);
            $response->send("Failure: Event not supported.");

            return;
        }

        $schema = $this->schemas[$name][$event];
        $this->validator->reset();
        $this->validator->check($payload, $schema);

        if (!$this->validator->isValid()) {
            $errors = $this->validator->getErrors();
            $errors = array_reduce($errors, function (string $carry, array $item): string {
                if ($item["property"]) {
                    return $carry . sprintf("\n%s: %s", $item["property"], $item["message"]);
                } else {
                    return $carry . "\n" . $item["message"];
                }
            }, "");

            $response->setStatus(400);
            $response->send("Failure: Payload validation failed." . $errors);

            return;
        }

        $message = $service->handle($headers, $payload);

        try {
            if ($message) {
                $req = (new HttpRequest)
                    ->setMethod("PUT")
                    ->setUri($this->config["api"] . "/messages")
                    ->setHeader("authorization", "Basic " . base64_encode("{$this->config['user_id']}:{$this->config['token']}"))
                    ->setBody(json_encode([
                        "room_id" => $hook->room_id,
                        "text" => $message->getText(),
                        "data" => $message->getData(),
                    ]));

                $resp = yield $this->http->request($req);

                if (intval($resp->getStatus() / 100) !== 2) {
                    $message = "API request failed: " . $resp->getStatus();

                    if ($resp->getBody()) {
                        $message .= "\n" . $resp->getBody();
                    }

                    throw new Exception($message);
                }
            }

            $response->send("Success: " . ($message ? "Message sent." : "Message skipped."));
        } catch (Exception $e) {
            $response->setStatus(500);
            $response->send("Failure: Couldn't persist message.");
        }
    }
}