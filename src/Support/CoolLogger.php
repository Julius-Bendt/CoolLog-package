<?php

namespace Bendt\CoolLog\Support;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Logger;
use Throwable;

class CoolLogger
{
    protected Request $request;
    protected string $sequence;

    public function __construct(Request $request = null)
    {
        $this->request = $request;
    }

    public function __invoke(array $config): Logger
    {
        $this->sequence = uniqid("seq", true);

        $logger = new Logger("coolLog");
        $cool_logger_handler = new CoolLoggerHandler(Logger::DEBUG, $config);
        $bufferHandler = new FingersCrossedHandler(
            $cool_logger_handler,
            Logger::ERROR,
            0,
            true,
            true,
            Logger::WARNING,
        );

        $logger->pushHandler($bufferHandler);

        collect($logger->getHandlers())
            ->each(function (FingersCrossedHandler $handler) use ($config) {
                $handler->pushProcessor(fn(array $record) => $this->processRecord($record, $config));
            });

        return $logger;
    }


    private function processRecord($record, array $config)
    {

        $exception_data = array_merge(
            [
                "params" => Arr::except($record["context"] ?? [], ["exception"]),
                "hostname" => gethostname(),
                "ip" => $this->request->server("REMOTE_ADDR"),
            ],
            $this->extractException($record["context"]["exception"] ?? null)
        );

        $stacktrace_path = "";
        try {
            $stacktrace_path = $this->generateS3Link($exception_data, $config);
        } catch (Exception $exception) {
            //TODO Slack logger, handle exception
        }


        $searchable_data = [
            "sequence" => $this->sequence,
            "level" => $record["level_name"],
            "user" => auth()->id() ?: null,
            "user_agent" => $this->request->server("HTTP_USER_AGENT"),
            "url" => $this->request->getUri(),
            "origin" => $this->request->headers->get("origin"),
            "app_name" => config("app.name"),
            "stacktrace_path" => $stacktrace_path,

            "message" => $record["message"],
            "file" => $exception_data["file"],
            "file_line" => $exception_data["line"],
            "exception_class" => $exception_data["type"],
            "status_code" => $exception_data["code"],
        ];

        $record["extra"] = array_merge($record["extra"] ?? [],
            $searchable_data,
        );

        return $record;
    }

    private function extractException(?Throwable $exception): array
    {
        if (!$exception) {
            return [];
        }

        return [
            "message" => $exception->getMessage(),
            "type" => get_class($exception),
            "code" => $exception->getCode(),
            "file" => $exception->getFile(),
            "line" => $exception->getLine(),
            "stacktrace" => collect($exception->getTrace())->map(function ($trace) {
                return Arr::except($trace, ["args"]);
            })->all(),
        ];
    }

    /**
     * @throws Exception
     */
    private function generateS3Link(array $data, array $config): string
    {
        if (isset($config["disk"])) {
            $prefix = $config["disk_prefix"] ?? config("app.name");
            $path = sprintf("$prefix/%s/%s.json", now()->format("Y-m-d"), md5(random_bytes(16)));

            Storage::disk($config["disk"])->put($path, json_encode($data, JSON_THROW_ON_ERROR));

            return $path;
        }

        return "";
    }
}
