Yii2 Spam protector
===================
Hide emails and phone numbers from spam robots

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist vasadibt/yii2-protector "*"
```

or add

```
"vasadibt/yii2-protector": "*"
```

to the require section of your `composer.json` file.


Configuration
-----
A simple example to integrate module.

```php           
'bootstrap' => [
    // ...
    'protector',
],
'components' => [
    // ...
    'protector' => [
         'class' => '\vasadibt\protector\ProtectData',
    ],
],
```



Usage
-----

Once the extension is installed, simply use it in your code by by html format:

```html
<h2>Contact</h2>

<a href="tel:[[protect:0036 70 1111 222]]">[[protect:0036 70 1111 222]]</a>

<a href="mailto:[[protect:info@mycompany.com]]">[[protect:info@mycompany.com]]</a>

<h3>Write to me<span class="email">[[protect:info@company.com]]</span></h3>
```

Or use php helpers

```php
<?= Yii::$app->protector->tel('0036 70 1111 222') ?>

<?= Yii::$app->protector->mailto('info@company.com') ?>

<span><?= Yii::$app->protector->protect('0036 70 1111 222') ?></span>
```

Features
-----

### Auto detect mode


If you want to use `Auto Detect` mode then turn on:

```php
'protector' => [
    'class' => '\vasadibt\protector\ProtectData',
    'enableAutoDetect' => 'true',
    'autoDetectPatterns' => [ // Optional you can use custom regex list  
        "/[-0-9a-zA-Z.+_]+@[-0-9a-zA-Z.+_]+.[a-zA-Z]{2,4}/U",
        // etc ... 
    ],
    'autoDetectMatchCallback' => function($match){ // Optional, if you want to validate match  
        return $match != 'bad@match.com';
    },
],
```

### Plugin turn off

If you want to disable plugin then set `enable` false:

```php
<?php Yii::$app->protector->enable = false; ?>
```

### Plugin debug

If you want to view key value paired generated list than enable `debug` mode and show javascript `protectorDebug` variable in html inspector:

```php
<?php Yii::$app->protector->debug = true; ?>
```
```js
console.log(protectorDebug);
```