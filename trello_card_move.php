<?php
/**
 * Trello Card Move
 * Version: 2.0
 * Author: John Money <johnmoney@pantheon.io>
 * 
 * Moves a Trello card on deploy with a [cardID] in the commit message. 
 * Requires Terminus Secrets Manager Plugin to set trello_key, trello_token, 
 * and trello_listid with scope=user,web.
 * 
 * Trello board list IDs can be retrieved via API:
 * https://api.trello.com/1/boards/{BOARDID}/lists?key={KEY}&token={TOKEN}
 * 
 * Example Terminus command:
 * terminus secret:site:set {SITE} trello_listid {LISTID} --scope=user,web
 * terminus secret:site:set {SITE}.test trello_listid {LISTID} --scope=user,web
 */

if (checkKeys() && strlen($_POST['deploy_message']) > 0)
{
  // Look for matches on a Trello card ID format
  // = [8 characters]
  preg_match('/\[[a-zA-Z0-9]{8}\]/', $_POST['deploy_message'], $matches);
  if (count($matches) > 0)
  {
    // Build the $commits['trello'] array so there is
    // only 1 item per ticket id
    foreach ($matches as $card_id_enc)
    {
      $card_id = substr($card_id_enc, 1, -1);
      moveCard($card_id);
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

  $key = pantheon_get_secret('trello_listid');
  if ($key === null || trim($key) === '')
    return false;

  return true;
}

/// <summary>
/// Move Trello card to list
/// </summary>
function moveCard($cardId) {
  $idList = pantheon_get_secret('trello_listid');
  print(" * moving $cardId to list $idList\n");

  $uri = 'https://api.trello.com/1/cards/' . $cardId . '?idList=' . $idList . '&key=' . pantheon_get_secret('trello_key') . '&token=' . pantheon_get_secret('trello_token');
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $uri);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_TIMEOUT, 5);
  $result = curl_exec($ch);
  curl_close($ch);
}
