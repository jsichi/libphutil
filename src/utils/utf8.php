<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Convert a string into valid UTF-8. This function is quite slow.
 *
 * When invalid byte subsequences are encountered, they will be replaced with
 * U+FFFD, the Unicode replacement character.
 *
 * @param   string  String to convert to valid UTF-8.
 * @return  string  String with invalid UTF-8 byte subsequences replaced with
 *                  U+FFFD.
 * @group utf8
 */
function phutil_utf8ize($string) {
  if (phutil_is_utf8($string)) {
    return $string;
  }

  // There is no function to do this in iconv, mbstring or ICU to do this, so
  // do it (very very slowly) in pure PHP.

  // TODO: Provide an optional fast C implementation ala fb_utf8ize() if this
  // ever shows up in profiles?

  $result = array();

  $regex =
    "/([\x01-\x7F]".
    "|[\xC2-\xDF][\x80-\xBF]".
    "|[\xE0-\xEF][\x80-\xBF][\x80-\xBF]".
    "|[\xF0-\xF4][\x80-\xBF][\x80-\xBF][\x80-\xBF])".
    "|(.)/";

  $offset = 0;
  $matches = null;
  while (preg_match($regex, $string, $matches, 0, $offset)) {
    if (!isset($matches[2])) {
      $result[] = $matches[1];
    } else {
      // Unicode replacement character, U+FFFD.
      $result[] = "\xEF\xBF\xBD";
    }
    $offset += strlen($matches[0]);
  }

  return implode('', $result);
}


/**
 * Determine if a string is valid UTF-8.
 *
 * @param string  Some string which may or may not be valid UTF-8.
 * @return bool    True if the string is valid UTF-8.
 * @group utf8
 */
function phutil_is_utf8($string) {
  if (function_exists('mb_check_encoding')) {
    // If mbstring is available, this is significantly faster than using PHP
    // regexps.
    return mb_check_encoding($string, 'UTF-8');
  }

  $regex =
    "/^(".
      "[\x01-\x7F]+".
    "|([\xC2-\xDF][\x80-\xBF])".
    "|([\xE0-\xEF][\x80-\xBF][\x80-\xBF])".
    "|([\xF0-\xF4][\x80-\xBF][\x80-\xBF][\x80-\xBF]))*\$/";

  return preg_match($regex, $string);
}


/**
 * Find the character length of a UTF-8 string.
 *
 * @param string A valid utf-8 string.
 * @return int   The character length of the string.
 * @group utf8
 */
function phutil_utf8_strlen($string) {
  if (function_exists('mb_strlen')) {
    return mb_strlen($string, 'UTF-8');
  }

  // TODO: This is terrifically slow.
  return count(phutil_utf8v($string));
}


/**
 * Split a UTF-8 string into an array of characters.
 *
 * NOTE: This function does not deal properly with combining characters.
 *
 * @param string A valid utf-8 string.
 * @return list  A list of characters in the string.
 * @group utf8
 */
function phutil_utf8v($string) {
  $res = array();
  $len = strlen($string);
  $ii = 0;
  while ($ii < $len) {
    $byte = $string[$ii];
    if ($byte <= "\x7F") {
      $res[] = $byte;
      $ii += 1;
      continue;
    } else if ($byte < "\xC0") {
      throw new Exception("Invalid UTF-8 string passed to phutil_utf8v().");
    } else if ($byte <= "\xDF") {
      $seq_len = 2;
    } else if ($byte <= "\xEF") {
      $seq_len = 3;
    } else if ($byte <= "\xF7") {
      $seq_len = 4;
    } else if ($byte <= "\xFB") {
      $seq_len = 5;
    } else if ($byte <= "\xFD") {
      $seq_len = 6;
    } else {
      throw new Exception("Invalid UTF-8 string passed to phutil_utf8v().");
    }

    if ($ii + $seq_len > $len) {
      throw new Exception("Invalid UTF-8 string passed to phutil_utf8v().");
    }
    for ($jj = 1; $jj < $seq_len; ++$jj) {
      if ($string[$ii + $jj] >= "\xC0") {
        throw new Exception("Invalid UTF-8 string passed to phutil_utf8v().");
      }
    }
    $res[] = substr($string, $ii, $seq_len);
    $ii += $seq_len;
  }
  return $res;
}

/**
 * Shorten a string to provide a summary, respecting UTF-8 characters. This
 * function attempts to truncate strings at word boundaries.
 *
 * NOTE: This function makes a best effort to apply some reasonable rules but
 * will not work well for the full range of unicode languages. For instance,
 * no effort is made to deal with combining characters.
 *
 * @param   string  UTF-8 string to shorten.
 * @param   int     Maximum length of the result.
 * @param   string  If the string is shortened, add this at the end. Defaults to
 *                  horizontal ellipsis.
 * @return  string  A string with no more than the specified character length.
 */
function phutil_utf8_shorten($string, $length, $terminal = "\xE2\x80\xA6") {
  $terminal_len = count(phutil_utf8v($terminal));
  if ($terminal_len >= $length) {
    // If you provide a terminal we still enforce that the result (including
    // the terminal) is no longer than $length, but we can't do that if the
    // terminal is too long.
    throw new Exception(
      "String terminal length must be less than string length!");
  }

  $string_v = phutil_utf8v($string);
  $string_len = count($string_v);

  if ($string_len <= $length) {
    // If the string is already shorter than the requested length, simply return
    // it unmodified.
    return $string;
  }

  // NOTE: This is not complete, and there are many other word boundary
  // characters and reasonable places to break words in the UTF-8 character
  // space. For now, this gives us reasonable behavior for latin langauges. We
  // don't necessarily have access to PCRE+Unicode so there isn't a great way
  // for us to look up character attributes.

  // If we encounter these, prefer to break on them instead of cutting the
  // string off in the middle of a word.
  static $break_characters = array(
    ' '   => true,
    "\n"  => true,
    ';'   => true,
    ':'   => true,
    '['   => true,
    '('   => true,
    ','   => true,
    '-'   => true,
  );

  // If we encounter these, shorten to this character exactly without appending
  // the terminal.
  static $stop_characters = array(
    '.'   => true,
    '!'   => true,
    '?'   => true,
  );

  // Search backward in the string, looking for reasonable places to break it.
  $word_boundary = null;
  $stop_boundary = null;

  // If we do a word break with a terminal, we have to look beyond at least the
  // number of characters in the terminal.
  $terminal_area = $length - $terminal_len;
  for ($ii = $length; $ii >= 0; $ii--) {
    $c = $string_v[$ii];

    if (isset($break_characters[$c]) && ($ii <= $terminal_area)) {
      $word_boundary = $ii;
    } else if (isset($stop_characters[$c]) && ($ii < $length)) {
      $stop_boundary = $ii + 1;
      break;
    } else {
      if ($word_boundary !== null) {
        break;
      }
    }
  }

  if ($stop_boundary !== null) {
    // We found a character like ".". Cut the string there, without appending
    // the terminal.
    $string_part = array_slice($string_v, 0, $stop_boundary);
    return implode('', $string_part);
  }

  // If we didn't find any boundary characters or we found ONLY boundary
  // characters, just break at the maximum character length.
  if ($word_boundary === null || $word_boundary === 0) {
    $word_boundary = $length - $terminal_len;
  }

  $string_part = array_slice($string_v, 0, $word_boundary);
  $string_part = implode('', $string_part);
  return $string_part.$terminal;
}
