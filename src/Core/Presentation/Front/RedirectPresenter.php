<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Front;

use Nette\Application\UI\Presenter;
use Nette\Http\IResponse;

/**
 * Where an address that no longer answers is sent, and where every other
 * spelling of an address that does answer is sent.
 *
 * A router cannot answer with a redirect - it turns a request into a page, and
 * that is all - so the catch-all turns "this address moved" into a request for
 * this page, and this page is the redirect. It renders nothing and has no
 * template, which is why it extends the framework's presenter rather than
 * Trilobit\Core\Presentation\Front\FrontPresenter: drawing the site's chrome
 * around a response nobody sees would be work done for nothing.
 *
 * The code is 301 and not 302 on purpose. A moved address has moved: a search
 * engine should forget the old one, a browser should stop asking, and a
 * bookmark should be rewritten. That is also why the address is only ever
 * redirected once something is known to answer at the destination - a
 * permanent redirect to nothing is remembered just as long as one to
 * something.
 */
final class RedirectPresenter extends Presenter
{
    /** As the presenter mapping in config/common.neon names this class. */
    public const string NAME = 'Core:Front:Redirect';

    /** The parameter the catch-all puts the destination in. */
    public const string TARGET = 'to';

    public function actionDefault(string $to): void
    {
        $this->redirectUrl(
            $this->getHttpRequest()->getUrl()->getBaseUrl() . $to,
            IResponse::S301_MovedPermanently,
        );
    }
}
