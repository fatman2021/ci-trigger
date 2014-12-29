<?php

namespace Piwik\Utils\Travis;

require_once 'vendor/autoload.php';

use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Http\Message\RequestInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Guzzle\Http\Client;

class TravisClient
{
    const PRO_ENDPOINT = "https://api.travis-ci.com";
    const NORMAL_ENDPOINT = "https://api.travis-ci.org";

    private $guzzleClient;
    private $proAccessToken;
    private $normalAccessToken;

    public function __construct($githubToken)
    {
        $this->guzzleClient = new Client();

        $this->normalAccessToken = $this->authenticate($githubToken, $isPro = false);
        $this->proAccessToken = $this->authenticate($githubToken, $isPro = true);
    }

    private function authenticate($githubToken, $isPro)
    {
        $response = $this->post("/auth/github", $isPro, array('github_token' => $githubToken));
        if (empty($response['access_token'])) {
            throw new \Exception("Authenticating against " . $this->getEndpoint($isPro) . " returned response w/o access_token: " . json_encode($response));
        }
        return $response['access_token'];
    }

    private function getEndpoint($isPro)
    {
        return $isPro ? self::PRO_ENDPOINT : self::NORMAL_ENDPOINT;
    }

    public function get($path, $isPro = null)
    {
        if ($isPro === null) {
            return array_merge_recursive(
                $this->get($path, true),
                $this->get($path, false)
            );
        }

        $request = $this->guzzleClient->get($this->getEndpoint($isPro) . $path);
        $request->setHeader('Accept', 'application/vnd.travis-ci.2+json');
        $this->setAuthorization($request, $isPro);
        return $request->send()->json();
    }

    public function post($path, $isPro = null, $bodyData = null)
    {
        if ($isPro === null) {
            return array_merge_recursive(
                $this->post($path, true, $bodyData),
                $this->post($path, false, $bodyData)
            );
        }

        $request = $this->guzzleClient->post($this->getEndpoint($isPro) . $path);
        $request->setHeader('Accept', 'application/vnd.travis-ci.2+json');
        $this->setAuthorization($request, $isPro);
        if (!empty($bodyData)) {
            $request->setBody(json_encode($bodyData), 'application/json');
        }
        return $request->send()->json();
    }

    private function setAuthorization(RequestInterface $request, $isPro)
    {
        $token = $isPro ? $this->proAccessToken : $this->normalAccessToken;
        if (!empty($token)) {
            $request->setHeader('Authorization', "token \"$token\"");
        }
    }
}

class TravisService
{
    private $client;
    private $isDryRun;

    public function __construct($githubToken, $isDryRun)
    {
        $this->client = new TravisClient($githubToken);
        $this->isDryRun = $isDryRun;
    }

    public function getAllRepos()
    {
        $proRepos = $this->client->get("/repos/?member=piwik-pro-travis-automation", $isPro = true);
        $nonProRepos = $this->client->get("/repos/?member=piwik-pro-travis-automation", $isPro = false);

        $result = array();
        foreach (@$proRepos['repos'] as $repo) {
            $repo['isPro'] = true;
            $result[] = $repo;
        }
        foreach (@$nonProRepos['repos'] as $repo) {
            $repo['isPro'] = false;
            $result[] = $repo;
        }
        return $result;
    }

    public function restartLatestBuild($repoSlug, $isPro)
    {
        $builds = $this->client->get("/repos/$repoSlug/builds", $isPro);

        if (empty($builds['builds'])) {
            throw new \Exception("No builds for repo!");
        }

        // find latest build for master
        $latestBuild = null;
        foreach ($builds['builds'] as $build) {
            if ($this->isBuildForMaster($builds['commits'], $build)) {
                $latestBuild = $build;
                break;
            }
        }

        if (!isset($latestBuild['id'])) {
            throw new \Exception("Build ID cannot be found in entity: " . json_encode($latestBuild));
        }

        $latestBuildId = @$latestBuild['id'];
        if ($this->isDryRun) {
            echo "[Dry Run] Restarting build $latestBuildId.\n";
        } else {
            $this->client->post("/builds/$latestBuildId/restart", $isPro);
        }
    }

    private function isBuildForMaster($commits, $build)
    {
        $commitId = $build['commit_id'];
        foreach ($commits as $commit) {
            if ($commitId == $commit['id']
                && $commit['branch'] == 'master'
            ) {
                return true;
            }
        }
        return false;
    }
}

class RestartAllBuilds extends Command
{
    const NAME = 'build:restart-all';

    protected function configure()
    {
        $this->setName(self::NAME);
        $this->setDescription("Restart the newest build of all repos a github user has access to in travis-ci.com and travis-ci.org.");
        $this->addOption('github-token', null, InputOption::VALUE_REQUIRED, "Github user token to use.");
        $this->addOption('include', null, InputOption::VALUE_REQUIRED, "Regex used with repo slugs to determine which repos to restart builds for, eg, '/plugin-'");
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, "If supplied, builds will not be actually restarted. Used for testing.");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $githubToken = $input->getOption('github-token');
        if (empty($githubToken)) {
            throw new InvalidArgumentException("--github-token required.");
        }

        $includeRegex = "/" . $input->getOption('include') . "/";
        $dryRun = $input->getOption('dry-run');

        $travis = new TravisService($githubToken, $dryRun);
        $allRepos = $travis->getAllRepos();

        foreach ($allRepos as $repoInfo) {
            $slug = $repoInfo['slug'];
            $isPro = $repoInfo['isPro'];

            if (!empty($includeRegex)
                && !preg_match($includeRegex, $slug)
            ) {
                $output->writeln("<comment>NOTE:</comment> Skipping repo <info>$slug</info>.");

                continue;
            }

            try {
                $travis->restartLatestBuild($slug, $isPro);

                $output->writeln("Restarted latest build for repo <info>$slug</info>.");
            } catch (\Exception $ex) {
                $output->writeln("<error>Failed to restart latest build for <info>$slug</info>:</error> " . $ex->getMessage());
                $output->writeln($ex->getTraceAsString());
            }
        }

        $output->writeln("<comment>Done.</comment>");
    }
}

class Script extends Application
{
    protected function getCommandName(InputInterface $input)
    {
        return RestartAllBuilds::NAME;
    }

    protected function getDefaultCommands()
    {
        $defaultCommands = parent::getDefaultCommands();
        $defaultCommands[] = new RestartAllBuilds();
        return $defaultCommands;
    }

    public function getDefinition()
    {
        $inputDefinition = parent::getDefinition();

        // clear out the normal first argument, which is the command name
        $inputDefinition->setArguments();

        return $inputDefinition;
    }
}

$application = new Script();
$application->run();
