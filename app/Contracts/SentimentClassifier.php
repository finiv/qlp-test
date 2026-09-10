<?php

namespace App\Contracts;

interface SentimentClassifier
{
    /**
     * Classify a customer reply.
     *
     * Returns the raw model response as a string. The model is instructed to
     * answer with JSON in the shape {"sentiment": "..."} where sentiment is
     * one of: interested, question, not_now, unsubscribe, wrong_person,
     * auto_reply.
     *
     * @throws \App\Exceptions\ClassifierTimeoutException
     */
    public function classify(string $body): string;
}
