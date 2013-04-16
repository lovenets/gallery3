<?php defined("SYSPATH") or die("No direct script access.");
/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2013 Bharat Mediratta
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
class Comment_Model_Comment extends ORM {
  function item() {
    return ORM::factory("Item", $this->item_id);
  }

  function author() {
    return Identity::lookup_user($this->author_id);
  }

  function author_name() {
    $author = $this->author();
    if ($author->guest) {
      return $this->guest_name;
    } else {
      return $author->display_name();
    }
  }

  function author_email() {
    $author = $this->author();
    if ($author->guest) {
      return $this->guest_email;
    } else {
      return $author->email;
    }
  }

  function author_url() {
    $author = $this->author();
    if ($author->guest) {
      return $this->guest_url;
    } else {
      return $author->url;
    }
  }

  /**
   * Add some custom per-instance rules.
   */
  public function validate(Validation $array=null) {
    // validate() is recursive, only modify the rules on the outermost call.
    if (!$array) {
      $this->rules = array(
        "guest_name"  => array("callbacks" => array(array($this, "valid_author"))),
        "guest_email" => array("callbacks" => array(array($this, "valid_email"))),
        "guest_url"   => array("rules"     => array("url")),
        "item_id"     => array("callbacks" => array(array($this, "valid_item"))),
        "state"       => array("rules"     => array("Model_Comment::valid_state")),
        "text"        => array("rules"     => array("required")),
      );
    }

    parent::validate($array);
  }

  /**
   * Handle any business logic necessary to save (i.e. create or update) a comment.
   * @see ORM::save()
   */
  public function save(Validation $validation=null) {
    $this->updated = time();
    $original_state = Arr::get($this->original_values(), "state");

    parent::save($validation);

    // We only notify on the related items if we're making a visible change.
    if (($this->state == "published") || ($original_state == "published")) {
      $item = $this->item();
      Module::event("item_related_update", $item);
    }

    return $this;
  }

  /**
   * Handle any business logic necessary to create a comment.
   * @see ORM::create()
   */
  public function create(Validation $validation=null) {
    $this->created = $this->updated;
    Module::event("comment_before_create", $this);

    if (empty($this->state)) {
      $this->state = "published";
    }

    // These values are useful for spam fighting, so save them with the comment.  It's painful to
    // check each one to see if it already exists before setting it, so just use server_name
    // as a semaphore for now (we use that in G2Import.php)
    if (empty($this->server_name)) {
      $this->server_http_accept = substr($_SERVER["HTTP_ACCEPT"], 0, 128);
      $this->server_http_accept_charset = substr($_SERVER["HTTP_ACCEPT_CHARSET"], 0, 64);
      $this->server_http_accept_encoding = substr($_SERVER["HTTP_ACCEPT_ENCODING"], 0, 64);
      $this->server_http_accept_language = substr($_SERVER["HTTP_ACCEPT_LANGUAGE"], 0, 64);
      $this->server_http_connection = substr($_SERVER["HTTP_CONNECTION"], 0, 64);
      $this->server_http_referer = substr($_SERVER["HTTP_REFERER"], 0, 255);
      $this->server_http_user_agent = substr($_SERVER["HTTP_USER_AGENT"], 0, 128);
      $this->server_name = substr((isset($_SERVER["SERVER_NAME"]) ?
        $_SERVER["SERVER_NAME"] : $_SERVER["HTTP_HOST"]), 0, 64);
      $this->server_query_string = substr($_SERVER["QUERY_STRING"], 0, 64);
      $this->server_remote_addr = substr($_SERVER["REMOTE_ADDR"], 0, 40);
      $this->server_remote_host = substr($_SERVER["REMOTE_HOST"], 0, 255);
      $this->server_remote_port = substr($_SERVER["REMOTE_PORT"], 0, 16);
    }

    parent::create($validation);
    Module::event("comment_created", $this);

    return $this;
  }

  /**
   * Handle any business logic necessary to update a comment.
   * @see ORM::update()
   */
  public function update(Validation $validation=null) {
    Module::event("comment_before_update", $this);
    $original = ORM::factory("Comment", $this->id);
    parent::update($validation);
    Module::event("comment_updated", $original, $this);

    return $this;
  }

  /**
   * Add a set of restrictions to any following queries to restrict access only to items
   * viewable by the active user.
   * @chainable
   */
  public function viewable() {
    $this->join("items")->on("item.id", "=", "comment.item_id");
    return Item::viewable($this);
  }

  /**
   * Make sure we have an appropriate author id set, or a guest name.
   */
  public function valid_author(Validation $v, $field) {
    if (empty($this->author_id)) {
      $v->add_error("author_id", "required");
    } else if ($this->author_id == Identity::guest()->id && empty($this->guest_name)) {
      $v->add_error("guest_name", "required");
    }
  }

  /**
   * Make sure that the email address is legal.
   */
  public function valid_email(Validation $v, $field) {
    if ($this->author_id == Identity::guest()->id) {
      if (empty($v->guest_email)) {
        $v->add_error("guest_email", "required");
      } else if (!Valid::email($v->guest_email)) {
        $v->add_error("guest_email", "invalid");
      }
    }
  }

  /**
   * Make sure we have a valid associated item id.
   */
  public function valid_item(Validation $v, $field) {
    if (DB::select()
        ->from("items")
        ->where("id", "=", $this->item_id)
        ->execute()->count() != 1) {
      $v->add_error("item_id", "invalid");
    }
  }

  /**
   * Make sure that the state is legal.
   */
  static function valid_state($value) {
    return in_array($value, array("published", "unpublished", "spam", "deleted"));
  }

  /**
   * Same as ORM::as_array() but convert id fields into their RESTful form.
   */
  public function as_restful_array() {
    $data = array();
    foreach ($this->as_array() as $key => $value) {
      if (strncmp($key, "server_", 7)) {
        $data[$key] = $value;
      }
    }
    $data["item"] = Rest::url("item", $this->item());
    unset($data["item_id"]);

    return $data;
  }
}