<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Preference;

use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\Http\IResponse;
use Trilobit\Core\Preference\PreferenceCatalogue;
use Trilobit\Core\Preference\RememberedPreferences;

/**
 * Where the browser says that somebody has chosen something.
 *
 * The switch changes the html element itself, so the page is already right
 * before this is called; what this is for is the remembering. It answers with a
 * status code and no body, because there is nothing for the caller to draw -
 * the change it is reporting has already happened on its own screen.
 *
 * **Why a request at all, when the switch needs none.** For somebody signed in,
 * the choice has to reach the profile at the moment it is made rather than
 * whenever they next load a page: the point of the profile is the other device,
 * which may be the next thing they open. The cookie is written here too, by the
 * framework, so that it carries the same flags every other cookie of this
 * application does and stays unreadable from JavaScript.
 *
 * The request has to come from a page of this site, read off the browser's own
 * Sec-Fetch-Site header - the same check nette/forms 3.3 makes on every signal
 * in place of a token in the page (see .ai/plans/08, decision D3). Nothing here
 * is worth stealing, but a page on another site being able to set what this one
 * looks like is still somebody else writing into a visitor's account.
 */
final class ChoicePresenter extends Presenter
{
    private const string SAME_SITE = 'same-origin';

    public function __construct(
        private readonly PreferenceCatalogue $catalogue,
        private readonly RememberedPreferences $remembered,
    ) {
        parent::__construct();
    }

    public function actionRemember(): void
    {
        $request = $this->getHttpRequest();

        // Every answer() below is followed by a return although it never comes
        // back - it ends in the framework's abort. The return is there so that
        // what happens next is legible to a reader and to the analyser, neither
        // of whom can see that from the call.
        if (!$request->isMethod('POST')) {
            $this->answer(IResponse::S405_MethodNotAllowed, 'A preference is changed by posting to this address.');

            return;
        }

        if ($request->getHeader('Sec-Fetch-Site') !== self::SAME_SITE) {
            $this->answer(IResponse::S403_Forbidden, 'A preference is changed from a page of this site.');

            return;
        }

        $name = $request->getPost('preference');
        $value = $request->getPost('value');

        if (!is_string($name) || !is_string($value) || !$this->catalogue->accepts($name, $value)) {
            $this->answer(
                IResponse::S400_BadRequest,
                sprintf('This build prefers one of: %s.', implode(', ', $this->catalogue->names())),
            );

            return;
        }

        $this->remembered->remember($name, $value, $this->getUser());

        $this->answer(IResponse::S204_NoContent, '');
    }

    /**
     * Says what happened and stops. Refusals carry a sentence rather than an
     * empty body: the only thing that calls this is assets/app.ts, and the
     * person who has to work out why it stopped working is looking at a network
     * panel.
     */
    private function answer(int $code, string $body): void
    {
        $this->getHttpResponse()->setCode($code);
        $this->sendResponse(new TextResponse($body));
    }
}
