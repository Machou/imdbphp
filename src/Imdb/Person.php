<?php

#############################################################################
# IMDBPHP                              (c) Giorgos Giagas & Itzchak Rehberg #
# written by Giorgos Giagas                                                 #
# extended & maintained by Itzchak Rehberg <izzysoft AT qumran DOT org>     #
# http://www.izzysoft.de/                                                   #
# ------------------------------------------------------------------------- #
# This program is free software; you can redistribute and/or modify it      #
# under the terms of the GNU General Public License (see doc/LICENSE)       #
#############################################################################

namespace Imdb;

use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * A person on IMDb
 * @author Izzy (izzysoft AT qumran DOT org)
 * @copyright 2008 by Itzchak Rehberg and IzzySoft
 */
class Person extends MdbBase
{
    protected $titleTypeMap = array(
      Title::MOVIE => Title::MOVIE,
      Title::TV_SERIES => Title::TV_SERIES,
      Title::TV_EPISODE => Title::TV_EPISODE,
      Title::TV_MINI_SERIES => Title::TV_MINI_SERIES,
      Title::TV_MOVIE => Title::TV_MOVIE,
      Title::TV_SPECIAL => Title::TV_SPECIAL,
      Title::TV_SHORT => Title::TV_SHORT,
      Title::GAME => Title::GAME,
      Title::VIDEO => Title::VIDEO,
      Title::SHORT => Title::SHORT,
      'Documentary' => Title::MOVIE,
      'TV Movie documentary' => Title::TV_MOVIE,
      'TV Series documentary' => Title::TV_SERIES,
      'Video documentary short' => Title::VIDEO,
      'Video documentary' => Title::VIDEO
    );

    // "Name" page:
    protected $main_photo = null;
    protected $fullname = "";
    protected $birthday = array();
    protected $deathday = array();
    protected $allfilms = array();
    protected $actressfilms = array();
    protected $actorsfilms = array();
    protected $producersfilms = array();
    protected $soundtrackfilms = array();
    protected $directorsfilms = array();
    protected $crewsfilms = array();
    protected $thanxfilms = array();
    protected $writerfilms = array();
    protected $selffilms = array();
    protected $archivefilms = array();
    protected $jsonLD = null;

    // "Bio" page:
    protected $birth_name = "";
    protected $nick_name = array();
    protected $bodyheight = array();
    protected $spouses = array();
    protected $bio_bio = array();
    protected $bio_trivia = array();
    protected $bio_tm = array();
    protected $bio_salary = array();
    protected $bio_quotes = array();

    // "Publicity" page:
    protected $pub_prints = array();
    protected $pub_movies = array();
    protected $pub_portraits = array();
    protected $pub_interviews = array();
    protected $pub_articles = array();
    protected $pub_pictorial = array();
    protected $pub_magcovers = array();
    protected $pub_pictorials = array();

    // SearchDetails
    protected $SearchDetails = array();

    public static function fromSearchResults(
        $id,
        $name,
        Config $config = null,
        LoggerInterface $logger = null,
        CacheInterface $cache = null
    ) {
        $person = new self($id, $config, $logger, $cache);
        $person->fullname = $name;
        return $person;
    }

    /**
     * @param string $id IMDBID to use for data retrieval
     * @param Config $config OPTIONAL override default config
     * @param LoggerInterface $logger OPTIONAL override default logger
     * @param CacheInterface $cache OPTIONAL override default cache
     */
    public function __construct(
        $id,
        Config $config = null,
        LoggerInterface $logger = null,
        CacheInterface $cache = null
    ) {
        parent::__construct($config, $logger, $cache);
        $this->setid($id);
    }

    /**
     * Retrieve the IMDB ID
     * @return string id IMDBID currently used
     */
    public function imdbid()
    {
        return $this->imdbID;
    }

    #-----------------------------------------------[ URL to person main page ]---

    /** Set up the URL to the movie title page
     * @return string url full URL to the current movies main page
     */
    public function main_url()
    {
        return "https://" . $this->imdbsite . "/name/nm" . $this->imdbid() . "/";
    }

    #=============================================================[ Main Page ]===
    #------------------------------------------------------------------[ Name ]---
    /** Get the name of the person
     * @return string name full name of the person
     * @see IMDB person page / (Main page)
     */
    public function name()
    {
        if (empty($this->fullname)) {
            $page = $this->getPage("Name");
            if (preg_match("/<title>(.*?) - IMDb<\/title>/i", $page, $match)) {
                $this->fullname = trim($match[1]);
            } elseif (preg_match("/<title>IMDb - (.*?)<\/title>/i", $page, $match)) {
                $this->fullname = trim($match[1]);
            }
        }
        return $this->fullname;
    }

    #--------------------------------------------------------[ Photo specific ]---

    /** Get cover photo
     * @param boolean $thumb (optional) thumb get the thumbnail (140x207, default)
     *                or the bigger variant with a maximum size of (1363x2048)
     * @return mixed photo (string url if found, FALSE otherwise)
     * @see IMDB person page / (Main page)
     */
    public function photo($thumb = true)
    {
        if ($this->main_photo === null) {
            $page = $this->getPage("Name");
            $this->main_photo = false;
            if (preg_match('!ipc-(?:poster--baseAlt|media--poster-m).*?<img.*?src="(.*?)"!ims', $page, $match)) {
                if ($thumb) {
                    $this->main_photo = $match[1];
                } else {
                    $this->main_photo = preg_replace('!(_V1_).*(\.[a-z]+$)!', '$1$2', $match[1]);
                }
            } elseif (($jsonLD = $this->jsonLD()) !== false) {
                // Fallback to bigger photo
                if (isset($jsonLD->image)) {
                    $this->main_photo = $jsonLD->image;
                }
            }
        }

        return $this->main_photo;
    }


    /**
     * Save the photo to disk
     * @param string $path where to store the file
     * @param boolean $thumb (optional) get the thumbnail (140x207, default)
     *                or the bigger variant with a maximum size of (1363x2048)
     * @return boolean success
     * @see IMDB person page / (Main page)
     */
    public function savephoto($path, $thumb = true, $rerun = false)
    {
        $photo_url = $this->photo($thumb);
        if (!$photo_url) {
            return false;
        }
        $req = new Request($photo_url, $this->config);
        $req->sendRequest();
        if (strpos($req->getResponseHeader("Content-Type"), 'image/jpeg') === 0
          || strpos($req->getResponseHeader("Content-Type"), 'image/gif') === 0
          || strpos($req->getResponseHeader("Content-Type"), 'image/bmp') === 0) {
            $fp = $req->getResponseBody();
        } else {
            if ($rerun) {
                $this->debug_scalar("<BR>*photoerror* at " . __FILE__ . " line " . __LINE__ . ": " . $photo_url . ": Content Type is '" . $req->getResponseHeader("Content-Type") . "'<BR>");
                return false;
            } else {
                $this->debug_scalar("<BR>Initiate second run for photo '$path'<BR>");
                return $this->savephoto($path, $thumb, true);
            }
        }
        $fp2 = fopen($path, "w");
        if ((!$fp) || (!$fp2)) {
            $this->debug_scalar("image error...<BR>");
            return false;
        }
        fputs($fp2, $fp);
        return true;
    }

    /** Get the URL for the movies cover photo
     * @param boolean $thumb (optional) get the thumbnail (140x207, default)
     *                or the bigger variant with a maximum size of (1363x2048)
     * @return mixed url (string URL or FALSE if none)
     * @see IMDB person page / (Main page)
     */
    public function photo_localurl($thumb = true)
    {
        if ($thumb) {
            $ext = "";
        } else {
            $ext = "_big";
        }
        if (!is_dir($this->photodir)) {
            $this->debug_scalar("<BR>***ERROR*** The configured image directory does not exist!<BR>");
            return false;
        }
        $path = $this->photodir . "nm" . $this->imdbid() . "{$ext}.jpg";
        if (@fopen($path, "r")) {
            return $this->photoroot . "nm" . $this->imdbid() . "{$ext}.jpg";
        }
        if (!is_writable($this->photodir)) {
            $this->debug_scalar("<BR>***ERROR*** The configured image directory lacks write permission!<BR>");
            return false;
        }
        if ($this->savephoto($path, $thumb)) {
            return $this->photoroot . "nm" . $this->imdbid() . "{$ext}.jpg";
        }
        return false;
    }

    #----------------------------------------------------------[ Filmographie ]---

    /** Get filmography
     * @param array &$res where to store the filmography
     * @param string $type name of the section to fetch filmography for e.g. 'actor', 'producer'
     */
    protected function filmograf(&$res, $type)
    {
        $page = $this->getPage("Fullcredits");
        preg_match("!<a name=\"$type\"(.*?(<div id=\"filmo|<script))!msi", $page, $match);
        if (empty($type)) {
            $match[1] = $page;
        } elseif (empty($match[1])) {
            $pos = strpos($page, '<a name="' . ucfirst($type) . '"');
            if ($pos) {
                $epos = strpos($page, '<div id=', $pos);
                $match[1] = substr($page, $pos, $epos - $pos);
            }
        }
        if (!empty($match) && preg_match_all('!<div class="filmo-row.*?>\s*(.*?)\s*</div!ims', $match[1], $matches)) {
            $mc = count($matches[0]);
            for ($i = 0; $i < $mc; ++$i) {
                $year = '';
                $type = Title::MOVIE;
                if (!preg_match(
                    '!href="/title/tt(\d{7,8})/[^"]*"\s*>(.*?)</a>\s*</b>\n?(.*)!ims',
                    $matches[1][$i],
                    $mov
                )) {
                    continue;
                }
                $char = array();
                if (preg_match('!<span class="year_column">[^<]*(\d{4})(.*?)</span>!ims', $matches[1][$i], $ty)) {
                    $year = $ty[1];
                }
                if (preg_match('!href="/character/ch(\d{7,8})[^"]*"\s*>(.*?)</a>!ims', $matches[1][$i], $char)) {
                    $chid = $char[1];
                    $chname = $char[2];
                } else {
                    $chid = '';
                    if (preg_match('!<br/>\s*([^>]+)\s*</*div!', $matches[0][$i], $char)) {
                        $chname = trim($char[1]);
                    } else {
                        $chname = '';
                    }
                }
                if (empty($chname)) {
                    switch ($type) {
                        case 'director':
                            $chname = 'Director';
                            break;
                        case 'producer':
                            $chname = 'Producer';
                            break;
                    }
                }

                if (preg_match("!\(([^\)]+)\)!", $mov[3], $typeMatch)) {
                    foreach ($this->titleTypeMap as $originalType => $trueType) {
                        if ($typeMatch[1] == $originalType) {
                            $type = $trueType;
                            break;
                        }
                    }
                }

                $addons = array();
                if (preg_match_all("!\((.+)\)!", $chname, $addonMatches)) {
                    $addons = $addonMatches[1];
                    $chname = trim(preg_replace("!\((.+)\)!", '', $chname));
                }

                $res[] = array(
                  "mid" => $mov[1],
                  "name" => $mov[2],
                  "year" => $year,
                  "title_type" => $type,
                  "chid" => $chid,
                  "chname" => trim($chname),
                  "addons" => $addons
                );
            }
        }
    }

    /** Get complete filmography
     *  This method ignores the categories and tries to collect the complete
     *  filmography. Useful e.g. for pages without categories on. It may, however,
     *  contain duplicates if there are categories and a movie is listed in more
     *  than one of them
     * @return array array[0..n][mid,name,year,title_type,chid,chname,addons], where chid is
     *         the character IMDB ID, chname the character name, and addons an
     *         array of additional remarks (the things in parenthesis)
     * @see IMDB person page / (Main page)
     */
    public function movies_all()
    {
        if (empty($this->allfilms)) {
            $this->filmograf($this->allfilms, "");
        }
        return $this->allfilms;
    }

    /**
     * Get an actor or actress' filmography
     * @return array array[0..n][mid,name,year,title_type,chid,chname,addons], where chid is
     *         the character IMDB ID, chname the character name, and addons an
     *         array of additional remarks (the things in parenthesis)
     * @see IMDB person page / (Main page)
     */
    public function movies_actor()
    {
        if (empty($this->actorsfilms)) {
            $this->filmograf($this->actorsfilms, "actor");
            $this->filmograf($this->actorsfilms, "actress");
        }
        return $this->actorsfilms;
    }

    /**
     * @deprecated Use self::movies_actor() instead
     */
    public function movies_actress()
    {
        if (empty($this->actressfilms)) {
            $this->filmograf($this->actressfilms, "actress");
        }
        return $this->actressfilms;
    }

    /** Get producers filmography
     * @return array array[0..n][mid,name,year,title_type,chid,chname,addons], where chid is
     *         the character IMDB ID, chname the character name, and addons an
     *         array of additional remarks (the things in parenthesis)
     * @see IMDB person page / (Main page)
     */
    public function movies_producer()
    {
        if (empty($this->producersfilms)) {
            $this->filmograf($this->producersfilms, "producer");
        }
        return $this->producersfilms;
    }

    /** Get directors filmography
     * @return array array[0..n][mid,name,year]
     * @see IMDB person page / (Main page)
     */
    public function movies_director()
    {
        if (empty($this->directorsfilms)) {
            $this->filmograf($this->directorsfilms, "director");
        }
        return $this->directorsfilms;
    }

    /** Get soundtrack filmography
     * @return array array[0..n][mid,name,year]
     * @see IMDB person page / (Main page)
     */
    public function movies_soundtrack()
    {
        if (empty($this->soundtrackfilms)) {
            $this->filmograf($this->soundtrackfilms, "soundtrack");
        }
        return $this->soundtrackfilms;
    }

    /** Get "Misc Crew" filmography
     * @return array array[0..n][mid,name,year]
     * @see IMDB person page / (Main page)
     */
    public function movies_crew()
    {
        if (empty($this->crewsfilms)) {
            $this->filmograf($this->crewsfilms, "miscellaneous");
        }
        return $this->crewsfilms;
    }

    /** Get "Thanx" filmography
     * @return array array[0..n][mid,name,year]
     * @see IMDB person page / (Main page)
     */
    public function movies_thanx()
    {
        if (empty($this->thanxfilms)) {
            $this->filmograf($this->thanxfilms, "thanks");
        }
        return $this->thanxfilms;
    }

    /** Get "Self" filmography
     * @return array array[0..n][mid,name,year,chid,chname], where chid is the
     *         character IMDB ID, and chname the character name
     * @see IMDB person page / (Main page)
     */
    public function movies_self()
    {
        if (empty($this->selffilms)) {
            $this->filmograf($this->selffilms, "self");
        }
        return $this->selffilms;
    }

    /** Get writers filmography
     * @return array array[0..n][mid,name,year,chid,chname], where chid is the
     *         character IMDB ID, and chname the character name
     * @see IMDB person page / (Main page)
     */
    public function movies_writer()
    {
        if (empty($this->writerfilms)) {
            $this->filmograf($this->writerfilms, "writer");
        }
        return $this->writerfilms;
    }

    /** Get "Archive Footage" filmography
     * @return array array[0..n][mid,name,year,chid,chname], where chid is the
     *         character IMDB ID, and chname the character name
     * @see IMDB person page / (Main page)
     */
    public function movies_archive()
    {
        if (empty($this->archivefilms)) {
            $this->filmograf($this->archivefilms, "archive_footage");
        }
        return $this->archivefilms;
    }

    #==================================================================[ /bio ]===
    #------------------------------------------------------------[ Birth Name ]---
    /** Get the birth name
     * @return string birthname
     * @see IMDB person page /bio
     */
    public function birthname()
    {
        if (empty($this->birth_name)) {
            $page = $this->getPage("Bio");
            if (preg_match("!Birth Name</td>\s*<td>(.*?)</td>\n!m", $page, $match)) {
                $this->birth_name = trim($match[1]);
            }
        }
        return $this->birth_name;
    }

    #-------------------------------------------------------------[ Nick Name ]---

    /** Get the nick name
     * @return array nicknames array[0..n] of strings
     * @see IMDB person page /bio
     */
    public function nickname()
    {
        if (empty($this->nick_name)) {
            $page = $this->getPage("Bio");
            if (preg_match("!Nicknames</td>\s*<td>\s*(.*?)</td>\s*</tr>!ms", $page, $match)) {
                $nicks = explode("<br/>", $match[1]);
                foreach ($nicks as $nick) {
                    $nick = trim($nick);
                    if (!empty($nick)) {
                        $this->nick_name[] = $nick;
                    }
                }
            } elseif (preg_match('!Nickname</td><td>\s*([^<]+)\s*</td>!', $page, $match)) {
                $this->nick_name[] = trim($match[1]);
            }
        }
        return $this->nick_name;
    }

    #------------------------------------------------------------------[ Born ]---

    /** Get Birthday
     * @return array|null birthday [day,month,mon,year,place]
     *         where month is the month name, and mon the month number
     * @see IMDB person page /bio
     */
    public function born()
    {
        if (empty($this->birthday)) {
            if (preg_match('|Born</td>(.*)</td|iUms', $this->getPage("Bio"), $match)) {
                preg_match('|/search/name\?birth_monthday=(\d+)-(\d+).*?\n?>(.*?) \d+<|', $match[1], $daymon);
                preg_match('|/search/name\?birth_year=(\d{4})|ims', $match[1], $dyear);
                preg_match('|/search/name\?birth_place=.*?"\s*>(.*?)<|ims', $match[1], $dloc);
                $this->birthday = array(
                  "day" => @$daymon[2],
                  "month" => @$daymon[3],
                  "mon" => @$daymon[1],
                  "year" => @$dyear[1],
                  "place" => @$dloc[1]
                );
            }
        }
        return $this->birthday;
    }

    #------------------------------------------------------------------[ Died ]---

    /**
     * Get date of death with place and cause
     * @return array [day,month,mon,year,place,cause]
     *         where month is the month name, and mon the month number
     * @see IMDB person page /bio
     */
    public function died()
    {
        if (empty($this->deathday)) {
            $page = $this->getPage("Bio");
            if (preg_match('|Died</td>(.*?)</td|ims', $page, $match)) {
                preg_match('|/search/name\?death_date=(\d+)-(\d+)-(\d+).*?\n?>(.*?) \d+<|', $match[1], $daymonyear);
                preg_match('|/search/name\?death_place=.*?"\s*>(.*?)<|ims', $match[1], $dloc);
                preg_match('/\(([^\)]+)\)/ims', $match[1], $dcause);
                $this->deathday = array(
                  "day" => @$daymonyear[3],
                  "month" => @$daymonyear[4],
                  "mon" => @$daymonyear[2],
                  "year" => @$daymonyear[1],
                  "place" => @trim(strip_tags($dloc[1])),
                  "cause" => @$dcause[1]
                );
            }
        }
        return $this->deathday;
    }

    #-----------------------------------------------------------[ Body Height ]---

    /** Get the body height
     * @return array [imperial,metric] height in feet and inch (imperial) an meters (metric)
     * @see IMDB person page /bio
     */
    public function height()
    {
        if (empty($this->bodyheight)) {
            $page = $this->getPage("Bio");
            if (preg_match(
                "!Height</td>\s*<td>\s*(?<imperial>.*?)\s*(&nbsp;)?\((?<metric>.*?)\)!m",
                $page,
                $match
            )) {
                $this->bodyheight["imperial"] = str_replace('&nbsp;', ' ', trim($match['imperial']));
                $this->bodyheight["metric"] = str_replace('&nbsp;', ' ', trim($match['metric']));
            }
        }
        return $this->bodyheight;
    }

    #----------------------------------------------------------------[ Spouse ]---

    /** Get spouse(s)
     * @return array [0..n] of array spouses [string imdb, string name, array from,
     *         array to, string comment, string children], where from/to are array
     *         [day,month,mon,year] (month is the name, mon the number of the month),
     *         comment usually is "divorced" (ouch), children is the number of children
     * @see IMDB person page /bio
     */
    public function spouse()
    {
        if (empty($this->spouses)) {
            $xp = $this->getXpathPage("Bio");
            $spouse = $xp->query("//table[contains(@id, 'tableFamily')]/tr[1]/td[1]");
            if ($spouse->count()) {
                if (trim($spouse->item(0)->nodeValue) == "Spouse") {
                    if ($tab = $xp->query("//table[contains(@id, 'tableFamily')]/tr[1]/td[2]")) {
                        $html = $tab->item(0)->ownerDocument->saveXML($tab->item(0));
                        $htmlParts = explode("<br/>", $html);
                        foreach ($htmlParts as $parts) {
                            // imdbid
                            $mid = '';
                            if (preg_match('/<a href="\/name\/nm(\d+).*">/', $parts, $url)) {
                                $mid = $url[1];
                            }
                            // spouse name
                            $name = '';
                            if (preg_match('![^(]*\([^(\d]*!', $parts, $nameRaw)) {
                                $nameClean = preg_replace('/[^A-Za-z0-9().\-\"\"\W ]/', '', strip_tags($nameRaw[0]));
                                if (strpos($nameClean, ')') && !strpos($nameClean, '?')) {
                                    $name = trim($nameClean);
                                    echo 'name';
                                } else {
                                    $nameClean = explode('(', $nameClean);
                                    if (!strpos($nameClean[0], '?')) {
                                        $name = trim($nameClean[0]);
                                    }
                                }
                            }
                            //Dates, comment and children
                            preg_match_all('!(\(.+?\))!ms', strip_tags($parts), $matches);
                            // remove leftover spouse name parts (imdbid 0001228 extra name between brackets)
                            if (!preg_match('~[0-9]+~', $matches[0][0]) && !strpos($matches[0][0], '?')) {
                                unset($matches[0][0]);
                                sort($matches[0]);
                            }
                            $datesRaw = preg_replace('/[^A-Za-z0-9-]/', ' ', $matches[0][0]);
                            //from date
                            $fromDay = '';
                            $fromMonth = '';
                            $fromYear = '';
                            $fromDateRaw = explode('-', $datesRaw);
                            if (preg_match('~[0-9]+~', $fromDateRaw[0])) {
                                $fromDate = array_values(array_filter(explode(' ', trim($fromDateRaw[0]))));
                                $count = count($fromDate);
                                if ($count == 1) {
                                    if (preg_match('~[0-9]+~', $fromDate[0])) {
                                        $fromYear = $fromDate[0];
                                    }
                                } elseif ($count == 2) {
                                    $fromMonth = trim($fromDate[0]);
                                    if (preg_match('~[0-9]+~', $fromDate[1])) {
                                        $fromYear = $fromDate[1];
                                    }
                                } elseif ($count == 3) {
                                    if (preg_match('~[0-9]+~', $fromDate[0])) {
                                        $fromDay = $fromDate[0];
                                    }
                                    $fromMonth = trim($fromDate[1]);
                                    if (preg_match('~[0-9]+~', $fromDate[2])) {
                                        $fromYear = $fromDate[2];
                                    }
                                }
                                $from = array(
                                      "day" => $fromDay,
                                      "month" => $fromMonth,
                                      "mon" => $this->monthNo($fromMonth),
                                      "year" => $fromYear
                                    );
                            } else {
                                $from = array("day" => '', "month" => '', "mon" => '', "year" => '');
                            }
                            //to date
                            $toDay = '';
                            $toMonth = '';
                            $toYear = '';
                            $toDateRaw = explode('-', $datesRaw);
                            if (array_key_exists(1, $toDateRaw) && preg_match('~[0-9]+~', $toDateRaw[1])) {
                                $toDate = array_values(array_filter(explode(' ', trim($toDateRaw[1]))));
                                $count = count($toDate);
                                if ($count == 1) {
                                    if (preg_match('~[0-9]+~', $toDate[0])) {
                                        $toYear = $toDate[0];
                                    }
                                } elseif ($count == 2) {
                                    $toMonth = trim($toDate[0]);
                                    if (preg_match('~[0-9]+~', $toDate[1])) {
                                        $toYear = $toDate[1];
                                    }
                                } elseif ($count == 3) {
                                    if (preg_match('~[0-9]+~', $toDate[0])) {
                                        $toDay = $toDate[0];
                                    }
                                    $toMonth = trim($toDate[1]);
                                    if (preg_match('~[0-9]+~', $toDate[2])) {
                                        $toYear = $toDate[2];
                                    }
                                }
                                $to = array(
                                      "day" => $toDay,
                                      "month" => $toMonth,
                                      "mon" => $this->monthNo($toMonth),
                                      "year" => $toYear
                                    );
                            } else {
                                $to = array("day" => '', "month" => '', "mon" => '', "year" => '');
                            }
                            // Comment and Children
                            $elements = count($matches[0]) - 1; //count remaining elements after dates
                            $comment = '';
                            $children = 0;
                            if ($elements == 1) {
                                if (preg_match('!(\d+) child!', $matches[0][1], $match)) {
                                    $children = $match[1];
                                } else {
                                    $comment = trim($matches[0][1], " ()");
                                }
                            } elseif ($elements == 2) {
                                //sometimes those 2 values are reversed, don't know why, so have to check.
                                if (preg_match('!(\d+) child!', $matches[0][1], $match)) {
                                    $children = $match[1];
                                    $comment = trim($matches[0][2], " ()");
                                } elseif (preg_match('!(\d+) child!', $matches[0][2], $match)) {
                                    $children = $match[1];
                                    $comment = trim($matches[0][1], " ()");
                                }
                            }
                            $this->spouses[] = array(
                              'imdb' => $mid,
                              'name' => $name,
                              'from' => $from,
                              'to' => $to,
                              'comment' => $comment,
                              'children' => (int)$children
                            );
                        }
                    }
                }
            }
        }
        return $this->spouses;
    }

    #---------------------------------------------------------------[ MiniBio ]---

    /** Get the person's mini bio
     * @return array bio array [0..n] of array[string desc, array author[url,name]]
     * @see IMDB person page /bio
     */
    public function bio()
    {
        if (empty($this->bio_bio)) {
            $page = $this->getPage("Bio");
            if (!$page) {
                return array();
            } // no such page
            if (preg_match(
                '!<h4 class="li_group">Mini Bio[^>]+?>(.+?)<(h4 class="li_group"|div class="article")!ims',
                $page,
                $block
            )) {
                preg_match_all(
                    '!<div class="soda.*?\s*<p>\s*(?<bio>.+?)\s</p>\s*<p><em>- IMDb Mini Biography By:\s*(?<author>.+?)\s*</em>!ims',
                    $block[1],
                    $matches
                );
                for ($i = 0; $i < count($matches[0]); ++$i) {
                    $bio_bio["desc"] = str_replace(
                        "href=\"/name/nm",
                        "href=\"https://" . $this->imdbsite . "/name/nm",
                        str_replace(
                            "href=\"/title/tt",
                            "href=\"https://" . $this->imdbsite . "/title/tt",
                            str_replace(
                                '/search/name',
                                'https://' . $this->imdbsite . '/search/name',
                                $matches['bio'][$i]
                            )
                        )
                    );
                    $author = 'Written by ' . (str_replace(
                        '/search/name',
                        'https://' . $this->imdbsite . '/search/name',
                        $matches['author'][$i]
                    ));
                    if (@preg_match('!href="(.+?)"[^>]*>\s*(.*?)\s*</a>!', $author, $match)) {
                        $bio_bio["author"]["url"] = $match[1];
                        $bio_bio["author"]["name"] = $match[2];
                    } else {
                        $bio_bio["author"]["url"] = '';
                        $bio_bio["author"]["name"] = trim($matches['author'][$i]);
                    }
                    $this->bio_bio[] = $bio_bio;
                }
            }
        }
        return $this->bio_bio;
    }

    #-----------------------------------------[ Helper to Trivia, Quotes, ... ]---

    /** Parse Trivia, Quotes, etc (same structs)
     * @param string $name
     * @param array &$res
     */
    protected function parparse($name, &$res)
    {
        $page = $this->getPage("Bio");
        $pos_s = strpos($page, '<h4 class="li_group">' . $name);
        if (!$pos_s) {
            return $res;
        }
        $pos_e = strpos($page, "<h4", $pos_s + 1);
        if (!$pos_e) {
            $pos_e = strpos($page, "</tbody", $pos_s + 1);
        }
        $block = substr($page, $pos_s, $pos_e - $pos_s);
        if (preg_match_all('!<div class="soda[^>]*>(.*?)</div>!ms', $block, $matches)) {
            foreach ($matches[1] as $match) {
                $res[] = str_replace(
                    'href="/name/nm',
                    'href="https://' . $this->imdbsite . '/name/nm',
                    str_replace('href="/title/tt', 'href="https://' . $this->imdbsite . '/title/tt', $match)
                );
            }
        }
    }

    #----------------------------------------------------------------[ Trivia ]---

    /** Get the Trivia
     * @return array trivia array[0..n] of string
     * @see IMDB person page /bio
     */
    public function trivia()
    {
        if (empty($this->bio_trivia)) {
            $this->parparse("Trivia", $this->bio_trivia);
        }
        return $this->bio_trivia;
    }

    #----------------------------------------------------------------[ Quotes ]---

    /** Get the Personal Quotes
     * @return array quotes array[0..n] of string
     * @see IMDB person page /bio
     */
    public function quotes()
    {
        if (empty($this->bio_quotes)) {
            $this->parparse("Personal Quotes", $this->bio_quotes);
        }
        return $this->bio_quotes;
    }

    #------------------------------------------------------------[ Trademarks ]---

    /** Get the "trademarks" of the person
     * @return array trademarks array[0..n] of strings
     * @see IMDB person page /bio
     */
    public function trademark()
    {
        if (empty($this->bio_tm)) {
            $this->parparse("Trade Mark", $this->bio_tm);
        }
        return $this->bio_tm;
    }

    #----------------------------------------------------------------[ Salary ]---

    /** Get the salary list
     * @return array salary array[0..n] of array movie[strings imdb,name,year], string salary
     * @see IMDB person page /bio
     */
    public function salary()
    {
        if (empty($this->bio_salary)) {
            $page = $this->getPage("Bio");
            $pos_s = strpos($page, '<table id="salariesTable"');
            if (!$pos_s) {
                return $this->bio_salary;
            }
            $pos_e = strpos($page, "</table", $pos_s);
            $block = substr($page, $pos_s, $pos_e - $pos_s);
            if (preg_match_all(
                "/<tr.*?<td.*?>(.*?)<\/td>.*?<td.*?>(.*?)<\/td>/ms",
                $block,
                $matches
            )) { // for each table row
                $mc = count($matches[0]);
                for ($i = 0; $i < $mc; ++$i) {
                    if (preg_match("/\/title\/tt(\d{7,8})\/\">(.*?)<\/a>\s*\((\d{4})\)/", $matches[1][$i], $match)) {
                        $movie["imdb"] = $match[1];
                        $movie["name"] = $match[2];
                        $movie["year"] = $match[3];
                    } else {
                        $movie["name"] = $matches[1][$i];
                    }
                    $this->bio_salary[] = array("movie" => $movie, "salary" => $matches[2][$i]);
                }
            }
        }
        return $this->bio_salary;
    }

    #============================================================[ /publicity ]===
    #-----------------------------------------------------------[ Print media ]---
    /** Print media about this person
     * @return array prints array[0..n] of array[author,title,place,publisher,year,isbn,url],
     *         where "place" refers to the place of publication, and "url" is a link to the ISBN
     * @see IMDB person page /publicity
     */
    public function pubprints()
    {
        if (empty($this->pub_prints)) {
            $page = $this->getPage("Publicity");
            $pos_s = strpos($page, "<h4 class=\"li_group\">Print Biographies (");
            $pos_e = strpos($page, "</table", $pos_s);
            $block = substr($page, $pos_s, $pos_e - $pos_s);
            $arr = explode("<td", $block);
            $pc = count($arr);
            for ($i = 1; $i < $pc; ++$i) {
                if (preg_match(
                    '/(.*).\s*<i>(.*)<\/i>\s*((.*):|)((.*),|)\s*((\d+)\.|)\s*ISBN\s*<a href="(.*)">(.*)<\/a>/iU',
                    $arr[$i],
                    $match
                )) {
                    $this->pub_prints[] = array(
                      "author" => $match[1],
                      "title" => htmlspecialchars_decode($match[2], ENT_QUOTES),
                      "place" => trim($match[4]),
                      "publisher" => htmlspecialchars_decode(trim($match[6]), ENT_QUOTES),
                      "year" => $match[8],
                      "isbn" => $match[10],
                      "url" => $match[9]
                    );
                } elseif (preg_match('/(.*).\s*<i>(.*)<\/i>\s*((.*):|)((.*),|)\s*((\d+)\.)/iU', $arr[$i], $match)) {
                    $this->pub_prints[] = array(
                      "author" => $match[1],
                      "title" => htmlspecialchars_decode($match[2], ENT_QUOTES),
                      "place" => trim($match[4]),
                      "publisher" => htmlspecialchars_decode(trim($match[6]), ENT_QUOTES),
                      "year" => $match[8],
                      "isbn" => "",
                      "url" => ""
                    );
                }
            }
        }
        return $this->pub_prints;
    }

    #----------------------------------------------[ Helper for movie parsing ]---

    /** Parse movie helper
     * @param array &$res where to store the results
     * @param string $header header of the block on the IMDB site
     * @brief helper to pubmovies() and portrayedmovies()
     */
    protected function parsepubmovies(&$res, $header)
    {
        $page = $this->getPage("Publicity");
        $pos_s = strpos($page, "<h4 class=\"li_group\">$header (");
        $pos_e = strpos($page, "<h4", $pos_s + 5);
        $skip = strlen($header) + 9;
        $block = substr($page, $pos_s + $skip, $pos_e - $pos_s - $skip);
        $arr = explode("<li", $block);
        $pc = count($arr);
        for ($i = 0; $i < $pc; ++$i) {
            if (preg_match('/href="\/title\/tt(\d+)\/">(.*)<\/a>\s*(\((\d+)\)|)/', $arr[$i], $match)) {
                $res[] = array("imdb" => $match[1], "name" => $match[2], "year" => $match[4]);
            }
        }
    }

    #----------------------------------------------------[ Biographical movies ]---

    /** Biographical Movies
     * @return array pubmovies array[0..n] of array[imdb,name,year]
     * @see IMDB person page /publicity
     */
    public function pubmovies()
    {
        if (empty($this->pub_movies)) {
            $this->parsepubmovies($this->pub_movies, "Film Biographies");
        }
        return $this->pub_movies;
    }

    #-----------------------------------------------------------[ Portrayed in ]---

    /** List of movies protraying the person
     * @return array pubmovies array[0..n] of array[imdb,name,year]
     * @see IMDB person page /publicity
     */
    public function pubportraits()
    {
        if (empty($this->pub_portraits)) {
            $this->parsepubmovies($this->pub_portraits, "Portrayals");
        }
        return $this->pub_portraits;
    }

    #--------------------------------------------[ Helper for Article parsing ]---

    /**
     * Helper for article parsing
     * @param string $title title of the block
     * @return array
     * @brief used by interviews(), articles(), pictorials(), magcovers()
     * @see IMDB person page /publicity
     */
    protected function parsearticles($title)
    {
        $page = $this->getPage("Publicity");
        $pos_s = strpos($page, "<h4 class=\"li_group\">$title (");
        if ($pos_s === false) {
            return array();
        }
        $pos_e = strpos($page, "</table", $pos_s);
        $block = substr($page, $pos_s, $pos_e - $pos_s);
        @preg_match_all("|<tr(.*)</tr>|ims", $block, $matches); // get the rows
        $res = array();
        foreach ($matches[0] as $row) {
            if (@preg_match('|<td.*?>(.*?)</td>.*<td.*?>(.*?)</td>|ms', $row, $match)) {
                @preg_match('/(\d{1,2}|)\s*(\S+|)\s*(\d{4}|)/i', $match[2], $dat);
                $datum = array(
                  "day" => $dat[1],
                  "month" => trim($dat[2]),
                  "mon" => $this->monthNo(trim($dat[2])),
                  "year" => trim($dat[3]),
                  "full" => trim($dat[0])
                );
                if (strlen($dat[0])) {
                    $match[2] = trim(substr($match[2], strlen($dat[0]) + 1));
                }
                @preg_match('|<a name="author">(.*?)</a>|ims', $match[2], $author);
                if (!empty($author) && strlen($author[0])) {
                    $match[2] = trim(str_replace(', by: ' . $author[0], '', $match[2]));
                }
                if (!empty($author)) {
                    $resauthor = $author[1];
                } else {
                    $resauthor = '';
                }
                $res[] = array(
                  "inturl" => '',
                  "name" => trim(strip_tags($match[1])),
                  "date" => $datum,
                  "details" => trim($match[2]),
                  "auturl" => '',
                  "author" => $resauthor
                );
            }
        }
        return $res;
    }

    #-------------------------------------------------------------[ Interviews ]---

    /** Interviews
     * @return array interviews array[0..n] of array[inturl,name,date,details,auturl,author]
     *         where all elements are strings - just date is an array[day,month,mon,year,full]
     *         (full: as displayed on the IMDB site)
     * @see IMDB person page /publicity
     */
    public function interviews()
    {
        if (empty($this->pub_interviews)) {
            $this->pub_interviews = $this->parsearticles("Interviews");
        }
        return $this->pub_interviews;
    }

    #--------------------------------------------------------------[ Articles ]---

    /** Articles
     * @return array articles array[0..n] of array[inturl,name,date,details,auturl,author]
     *         where all elements are strings - just date is an array[day,month,mon,year,full]
     *         (full: as displayed on the IMDB site)
     * @see IMDB person page /publicity
     */
    public function articles()
    {
        if (empty($this->pub_articles)) {
            $this->pub_articles = $this->parsearticles("Articles");
        }
        return $this->pub_articles;
    }

    #-------------------------------------------------------------[ Pictorials ]---

    /** Pictorials
     * @return array pictorials array[0..n] of array[inturl,name,date,details,auturl,author]
     *         where all elements are strings - just date is an array[day,month,mon,year,full]
     *         (full: as displayed on the IMDB site)
     * @see IMDB person page /publicity
     */
    public function pictorials()
    {
        if (empty($this->pub_pictorials)) {
            $this->pub_pictorials = $this->parsearticles("Pictorials");
        }
        return $this->pub_pictorials;
    }

    #--------------------------------------------------------------[ Magazines ]---

    /** Magazine cover photos
     * @return array magcovers array[0..n] of array[inturl,name,date,details,auturl,author]
     *         where all elements are strings - just date is an array[day,month,mon,year,full]
     *         (full: as displayed on the IMDB site)
     * @see IMDB person page /publicity
     */
    public function magcovers()
    {
        if (empty($this->pub_magcovers)) {
            $this->pub_magcovers = $this->parsearticles("Magazine Covers");
        }
        return $this->pub_magcovers;
    }

    #---------------------------------------------------------[ Search Details ]---

    /** Set some search details
     * @param string $role
     * @param integer $mid IMDB ID
     * @param string $name movie-name
     * @param integer $year
     */
    public function setSearchDetails($role, $mid, $name, $year)
    {
        $this->SearchDetails = array("role" => $role, "mid" => $mid, "moviename" => $name, "year" => $year);
    }

    /** Get the search details
     *  They are just set when the imdb_person object has been initialized by the
     *  imdbpsearch class
     * @return array SearchDetails (mid,name,role,moviename,year)
     */
    public function getSearchDetails()
    {
        return $this->SearchDetails;
    }

    /**
     * Get the ID for the title we're using. There might have been a redirect from the ID given in the constructor
     * @return string|null e.g. 0133093 not including nm!
     */
    public function real_id()
    {
        $page = $this->getPage('Name');
        if (preg_match('#<meta property="imdb:pageConst" content="nm(\d+)"#', $page, $matches)) {
            if (!empty($matches[1])) {
                return $matches[1];
            }
        }
        return null;
    }

    /**
     * @param string $pageName internal name of the page
     * @return string
     */
    protected function getUrlSuffix($pageName)
    {
        switch ($pageName) {
            case "Name":
                $urlname = "/";
                break;

            case "Bio":
                $urlname = "/bio";
                break;

            case "Publicity":
                $urlname = "/publicity";
                break;

            case "Fullcredits":
                $urlname = "/fullcredits";
                break;

            default:
                throw new \Exception("Could not find URL for page $pageName");
        }

        return $urlname;
    }

    protected function buildUrl($page = null)
    {
        return "https://" . $this->imdbsite . "/name/nm" . $this->imdbID . $this->getUrlSuffix($page);
    }

    /**
     * @param string $page Name of the actor page to fetch
     * @return string
     * @see Person::getUrlSuffix()
     */
    protected function getPage($page = null)
    {
        if (!empty($this->page[$page])) {
            return $this->page[$page];
        }

        $this->page[$page] = parent::getPage($page);

        return $this->page[$page];
    }

    protected function jsonLD()
    {
        if ($this->jsonLD !== null) {
            return $this->jsonLD;
        }

        $page = $this->getPage("Name");
        $jsonLD = false;

        preg_match('#<script type="application/ld\+json">(.+?)</script>#ims', $page, $matches);

        if (isset($matches[1])) {
            $jsonLD = json_decode($matches[1]);
        }

        return $this->jsonLD = $jsonLD;
    }
}
