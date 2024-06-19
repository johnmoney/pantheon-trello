<?php
/**
 * Trello Card Comment
 * Version: 2.0
 * Author: John Money <johnmoney@pantheon.io>
 * 
 * Posts a new comment to Trello card for each a commit with a [cardID] in the 
 * commit message. Requires Terminus Secrets Manager Plugin to set trello_key, 
 * trello_token and trello_boardid with scope=user, web.
 */

 //Trello supports markdown syntax in comments
define("COMMENT_FORMAT", "**%author** %message on %server");

//config options
$commentsOnMultidevByCardId = false;
$commentsOnMultidevByEnvName = true;

if (checkKeys())
{
  $commits = getCommits($_SERVER['HOME'] . "/files/{$_ENV['PANTHEON_ENVIRONMENT']}_trello_last_commit.txt");

  $processedCommits = array('trello' => array());
  if ($_ENV['PANTHEON_ENVIRONMENT'] == "dev" || $commentsOnMultidevByCardId)
  {
    $processedCommits = getCommitsByCardId($commits);
  }
  else if ($_ENV['PANTHEON_ENVIRONMENT'] != "dev" && $commentsOnMultidevByEnvName)
  {
    if ($shortLink = getShortlink($_SERVER['HOME'] . "/files/{$_ENV['PANTHEON_ENVIRONMENT']}_trello_shortlink.txt", $_ENV['PANTHEON_ENVIRONMENT']))
    {
      $processedCommits = getCommitsByEnvName($commits, $shortLink);
    }
  }

  // Check each commit message for Trello card IDs
  foreach ($processedCommits['trello'] as $card_id => $commit_ids) {
    foreach ($commit_ids as $commit_id) {
      postComment($card_id, 
        array(
          '%message' => $processedCommits['message'][$commit_id],
          '%author' => $processedCommits['author'][$commit_id],
          '%server' => "[{$_ENV['PANTHEON_ENVIRONMENT']}](https://{$_ENV['DRUSH_OPTIONS_URI']})"
        )
      );
    }
  }
}

/// <summary>
/// Check if requried keys are set
/// </summary>
/// <returns>
/// Bool true if keys are not null or empty
/// </returns>
function checkKeys()
{
  $key = pantheon_get_secret('trello_key');
  if ($key === null || trim($key) === '')
    return false;

  $key = pantheon_get_secret('trello_token');
  if ($key === null || trim($key) === '')
    return false;

  $key = pantheon_get_secret('trello_boardid');
  if ($key === null || trim($key) === '')
    return false;

  return true;
}

/// <summary>
/// Do git operations to find all commits between the specified commit hashes
/// </summary>
/// <returns>
/// Associative array containing all applicable commits that contain references to Trello cards.
/// </returns>
function getCommits($filepath)
{
  // Get latest commit
  $current_commithash = shell_exec('git rev-parse HEAD');
  $last_commithash = false;

  // Retrieve the last commit processed by this script
  if (file_exists($filepath)) {
    $last_processed_commithash = trim(file_get_contents($filepath));
    // We should (almost) always find our last commit still in the repository;
    // if the user has force-pushed a branch, though, then our last commit
    // may be overwritten.  If this happens, only process the most recent commit.
    exec("git rev-parse $last_processed_commithash 2> /dev/null", $output, $status);
    if (!$status) {
      $last_commithash = $last_processed_commithash;
    }
  }

  // Update the last commit file with the latest commit
  file_put_contents($filepath, $current_commithash, LOCK_EX);

  // Get commits in range
  $cmd = 'git log --pretty="format:%h|%s|%cn"'; // add -p to include diff
  if (!$last_commithash)
    $cmd .= ' -n 1';
  else
    $cmd .= ' ' . $last_commithash . '...' . $current_commithash;

  $cmdResult = shell_exec($cmd);

  return explode("\n", $cmdResult);
}

function getCommitsByCardId($commitsRaw)
{
  $commits = array(
    // Formatted array of commits being sent to Trello
    'message' => array(),
    'author' => array(),
    // An array keyed by Trello card id, each holding an
    // array of commit ids.
    'trello' => array()
  );

  foreach ($commitsRaw as $line)
  {
    list($commitId, $message, $author) = explode("|", $line, 3);

    // Look for matches on a Trello card ID format
    // = [8 characters]
    preg_match('/\[[a-zA-Z0-9]{8}\]/', $message, $matches);
    if (count($matches) > 0)
    {
      // Build the $commits['trello'] array so there is
      // only 1 item per ticket id
      foreach ($matches as $card_id_enc)
      {
        $card_id = substr($card_id_enc, 1, -1);
        if (!isset($commits['trello'][$card_id]))
        {
          $commits['trello'][$card_id] = array();
        }
        // ... and only 1 item per commit id
        $commits['trello'][$card_id][$commitId] = $commitId;
      }
      // Add the commit to the history array since there was a match.
      $commits['message'][$commitId] = $message;
      $commits['author'][$commitId] = $author;
    }
  }

  return $commits;
}

function getCommitsByEnvName($commitsRaw, $shortLink)
{
  $commits = array(
    // Formatted array of commits being sent to Trello
    'message' => array(),
    'author' => array(),
    // An array keyed by Trello card id, each holding an
    // array of commit ids.
    'trello' => array()
  );

  //find a valid shortLink from env name
  foreach ($commitsRaw as $line)
  {
    list($commitId, $message, $author) = explode("|", $line, 3);

    $commits['trello'][$shortLink][$commitId] = $commitId;
    $commits['message'][$commitId] = $message;
    $commits['author'][$commitId] = $author;
  }

  return $commits;
}

function getShortlink($filepath, $env)
{
  $shortLink = false;

  // Retrieve cached shortlink by this script
  if (file_exists($filepath)) {
    $shortLink = trim(file_get_contents($filepath));
    echo " * cached shortLink: $shortLink\n";
  }
  else
  {
    $uri = 'https://api.trello.com/1/boards/' . pantheon_get_secret('trello_boardid') . '/cards?&key=' . pantheon_get_secret('trello_key') . '&token=' . pantheon_get_secret('trello_token');
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $uri);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $result = curl_exec($ch);
    curl_close($ch);
  
    foreach (json_decode($result, true) as $item)
    {
      if (strcasecmp($env, $item['shortLink']) == 0)
      {
        $shortLink = $item['shortLink'];
        echo " * api returned shortLink:" . $item['shortLink'] . "\n";
        break;
      }
    }

    file_put_contents($filepath, $shortLink, LOCK_EX);
  }

  return $shortLink;
}

/// <summary>
/// Post comment on Trello card
/// </summary>
function postComment($cardId, $data) {
  print(" * commenting on $cardId\n");

  $payload = array(
    'text' => str_replace(array_keys($data), array_values($data), COMMENT_FORMAT),
    'key' => pantheon_get_secret('trello_key'),
    'token' => pantheon_get_secret('trello_token')
  );

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, 'https://api.trello.com/1/cards/' . $cardId . '/actions/comments');
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_TIMEOUT, 5);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  $result = curl_exec($ch);
  curl_close($ch);
}
