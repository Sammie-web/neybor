import { useState, type FormEvent } from 'react';

const apiBase = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api/v1';
type Audience = 'customer' | 'provider';
type FormState = { name: string; email: string; phone: string; business_name: string; service_interest: string };
const emptyForm: FormState = { name: '', email: '', phone: '', business_name: '', service_interest: '' };
const services = ['Cleaning', 'Electrical', 'Plumbing', 'Hair & beauty', 'Repairs & maintenance', 'Moving & delivery', 'Other'];

function Logo({ light = false }: { light?: boolean }) {
  return <a className={`logo ${light ? 'logo-light' : ''}`} href="#home" aria-label="Neybor home"><span className="logo-mark">N</span><span>neybor</span></a>;
}

function Arrow() { return <svg aria-hidden="true" viewBox="0 0 20 20" width="17" height="17" fill="none"><path d="M3.5 10h12m-5-5 5 5-5 5" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" /></svg>; }

function App() {
  const [audience, setAudience] = useState<Audience>('customer');
  const [form, setForm] = useState<FormState>(emptyForm);
  const [state, setState] = useState<'idle' | 'sending' | 'success' | 'duplicate' | 'error'>('idle');
  const [error, setError] = useState('');
  const [menuOpen, setMenuOpen] = useState(false);

  function chooseAudience(next: Audience) {
    setAudience(next);
    setState('idle');
    setError('');
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (state === 'sending') return;
    setState('sending');
    setError('');
    try {
      const response = await fetch(`${apiBase}/waitlist`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({
          ...form,
          role: audience,
          phone: form.phone.trim() || undefined,
          business_name: audience === 'provider' ? form.business_name.trim() : undefined,
          service_interest: form.service_interest || undefined,
        }),
      });
      const result = await response.json() as { message?: string; code?: string; errors?: Record<string, string[]> };
      if (!response.ok) {
        const detail = Object.values(result.errors ?? {}).flat()[0];
        throw new Error(detail ?? result.message ?? 'Your details could not be submitted. Please try again.');
      }
      setState(result.code === 'already_registered' ? 'duplicate' : 'success');
      setForm(emptyForm);
    } catch (reason) {
      setState('error');
      setError(reason instanceof Error ? reason.message : 'Something went wrong. Please try again.');
    }
  }

  const nav = [
    ['About', '#about'], ['How it works', '#how-it-works'], ['Services', '#services'], ['FAQs', '#faqs'],
  ];

  return <div className="site-shell">
    <header className="site-header">
      <div className="site-nav-wrap"><Logo />
        <nav className={`site-nav ${menuOpen ? 'site-nav-open' : ''}`} aria-label="Main navigation">
          {nav.map(([label, href]) => <a key={href} href={href} onClick={() => setMenuOpen(false)}>{label}</a>)}
          <a href="#contact" onClick={() => setMenuOpen(false)}>Contact</a>
        </nav>
        <a className="nav-cta" href="#waitlist">Get early access <Arrow /></a>
        <button className="menu-toggle" onClick={() => setMenuOpen((value) => !value)} aria-expanded={menuOpen} aria-label={menuOpen ? 'Close menu' : 'Open menu'}>{menuOpen ? '×' : '☰'}</button>
      </div>
    </header>

    <main>
      <section className="hero-section" id="home">
        <div className="hero-content">
          <div className="launch-pill"><span /> COMING SOON TO BENIN CITY</div>
          <h1>Good work,<br /><em>close by.</em></h1>
          <p className="hero-copy">The people who can help are closer than you think. Neybor is making it easier to find reliable local professionals — and easier for good work to find them.</p>
          <div className="hero-actions"><a className="button button-dark" href="#waitlist">Be first to know <Arrow /></a><a className="text-link" href="#how-it-works">See how it works <span>↓</span></a></div>
          <div className="hero-location"><span className="pin">⌖</span><span>Starting in Benin City, Edo State</span></div>
        </div>
        <div className="hero-visual" aria-label="Illustration of local service professionals around Benin City">
          <div className="map-wash"><span className="map-street street-a" /><span className="map-street street-b" /><span className="map-street street-c" /><span className="map-street street-d" /><span className="map-street street-e" /><span className="map-river" />
            <div className="map-label label-one">GRA</div><div className="map-label label-two">NEW BENIN</div><div className="map-label label-three">RING ROAD</div>
            <div className="map-marker marker-one"><span className="marker-avatar">E</span><span className="marker-caption">Electrical</span></div>
            <div className="map-marker marker-two"><span className="marker-avatar avatar-clay">P</span><span className="marker-caption">Plumbing</span></div>
            <div className="map-marker marker-three"><span className="marker-avatar avatar-sun">C</span><span className="marker-caption">Cleaning</span></div>
            <div className="you-marker"><span /><small>You</small></div>
            <div className="map-card"><div className="map-card-icon">✳</div><div><strong>Good people, nearby</strong><small>Discover your local Neybors</small></div><span className="online-dot" /></div>
            <div className="map-compass">N<br /><span>⌃</span></div>
          </div>
          <div className="visual-note"><span className="note-line" /> A neighbourhood, better connected.</div>
        </div>
        <div className="hero-orbit orbit-a" /><div className="hero-orbit orbit-b" />
      </section>

      <section className="trust-strip" aria-label="Neybor principles"><div><span>01</span> Near you, by design</div><i /><div><span>02</span> People you can trust</div><i /><div><span>03</span> Made for local life</div></section>

      <section className="intro-section section-wrap" id="about">
        <div className="section-kicker">A BETTER WAY TO FIND HELP</div>
        <div className="intro-grid"><h2>Local know-how.<br /><em>Without the runaround.</em></h2><div className="intro-copy"><p>Finding someone dependable shouldn't mean asking five group chats and hoping for the best. Neybor brings local services into one thoughtful place — so you can find the right professional, understand what they offer, and get on with your day.</p><p>We’re building for the neighbourhoods we know, starting right here in Benin City.</p><a className="arrow-link" href="#waitlist">Join the first neighbourhood <Arrow /></a></div></div>
      </section>

      <section className="how-section" id="how-it-works"><div className="section-wrap"><div className="section-header"><div><div className="section-kicker">A LITTLE LESS FRICTION</div><h2>From “who do you know?”<br />to <em>“found my Neybor.”</em></h2></div><p>Simple for the person looking.<br />Meaningful for the person doing.</p></div>
        <div className="steps-grid">{[
          ['01', 'Tell us what you need', 'A clean, a repair, a fresh cut. Search by service and set the location that works for you.'],
          ['02', 'See who’s nearby', 'Explore local professionals, get a feel for their work, and compare the details that matter.'],
          ['03', 'Make it happen', 'Send a request and follow along as your service moves from looking to booked.'],
        ].map(([number, title, copy]) => <article className="step-card" key={number}><span className="step-number">{number}</span><div className={`step-art art-${number}`}><div className="art-card"><span className="art-symbol">{number === '01' ? '⌕' : number === '02' ? '⌖' : '✓'}</span><span className="art-lines"><i /><i /><i /></span></div><span className="art-orbit" /></div><h3>{title}</h3><p>{copy}</p></article>)}</div>
      </div></section>

      <section className="benefit-section section-wrap"><div className="benefit-card customer-card"><div className="section-kicker">FOR YOUR EVERYDAY</div><h2>A good find,<br /><em>right around the corner.</em></h2><p>Spend less time chasing recommendations and more time getting things done. Discover services around you, compare with confidence, and make a plan that fits.</p><a href="#waitlist" onClick={() => chooseAudience('customer')} className="button button-light">I’m looking for a service <Arrow /></a><div className="benefit-illustration customer-illustration"><div className="sun-disc" /><span className="floating-icon icon-home">⌂</span><span className="floating-icon icon-star">✳</span><div className="path-line" /><div className="benefit-pin">N</div></div></div>
        <div className="benefit-card provider-card"><div className="section-kicker">FOR THE PEOPLE WHO DO THE WORK</div><h2>Your skill deserves<br /><em>a wider neighbourhood.</em></h2><p>Make your services easier to find, show the care you put into your work, and connect with customers nearby who need what you do.</p><a href="#waitlist" onClick={() => chooseAudience('provider')} className="button button-light">I offer a service <Arrow /></a><div className="benefit-illustration provider-illustration"><div className="provider-circle circle-back" /><div className="provider-circle circle-front"><span>✦</span></div><span className="provider-bubble bubble-a">A good job.</span><span className="provider-bubble bubble-b">A new neighbour.</span></div></div>
      </section>

      <section className="services-section" id="services"><div className="section-wrap"><div className="section-header"><div><div className="section-kicker">THE EVERYDAY, TAKEN CARE OF</div><h2>Good people for<br /><em>the things that matter.</em></h2></div><p>We’re starting with the services our<br />neighbours ask for most.</p></div><div className="service-grid">
        {[
          ['⌂', 'Home & repairs', 'Electrical · Plumbing · General repairs', 'svc-green'],
          ['✧', 'Cleaning & care', 'Home cleaning · Laundry · Deep clean', 'svc-peach'],
          ['✳', 'Personal care', 'Barbers · Hair · Beauty & wellness', 'svc-lilac'],
          ['↗', 'Getting things done', 'Mechanics · Moving · Local delivery', 'svc-blue'],
        ].map(([icon, title, copy, tone]) => <article className={`service-card ${tone}`} key={title}><span className="service-icon">{icon}</span><h3>{title}</h3><p>{copy}</p><span className="service-arrow"><Arrow /></span></article>)}
      </div><p className="service-note">This is just the beginning. More local skills, as our neighbourhood grows.</p></div></section>

      <section className="waitlist-section" id="waitlist"><div className="waitlist-shape shape-a" /><div className="waitlist-shape shape-b" /><div className="waitlist-wrap">
        <div className="waitlist-copy"><div className="section-kicker">BE PART OF THE BEGINNING</div><h2>Good things grow<br />from <em>good neighbours.</em></h2><p>Join the early list. We’ll let you know when Neybor is ready to welcome you — no noise, just the useful news.</p><div className="waitlist-promise"><span>✳</span><p><strong>Made for Benin City.</strong><br />Growing one neighbourhood at a time.</p></div></div>
        <div className="waitlist-form-card"><div className="audience-toggle" role="group" aria-label="Choose your waitlist">
          <button type="button" className={audience === 'customer' ? 'audience-active' : ''} onClick={() => chooseAudience('customer')}>I need a service</button>
          <button type="button" className={audience === 'provider' ? 'audience-active' : ''} onClick={() => chooseAudience('provider')}>I offer a service</button>
        </div>
          {state === 'success' || state === 'duplicate' ? <div className="form-success" role="status"><span className="success-check">{state === 'success' ? '✓' : '↗'}</span><h3>{state === 'success' ? 'You’re on the list.' : 'You’re already on the list.'}</h3><p>{state === 'success' ? 'We’ll be in touch when Neybor is ready for you. Thanks for being here early.' : 'We’ve got your details. We’ll share launch news when it’s ready.'}</p><button className="text-link" onClick={() => { setState('idle'); setForm(emptyForm); }}>Add another person <Arrow /></button></div> :
          <form className="waitlist-form" onSubmit={submit} aria-busy={state === 'sending'}>
            <p className="form-eyebrow">{audience === 'customer' ? 'YOUR NEIGHBOURHOOD STARTS HERE' : 'LET YOUR WORK TRAVEL FURTHER'}</p><h3>{audience === 'customer' ? 'Count me in.' : 'Help me get discovered.'}</h3><p className="form-copy">{audience === 'customer' ? 'Be first to find helpful people close by.' : 'Be first to reach new customers around you.'}</p>
            <label>Your name<input autoComplete="name" name="name" required minLength={2} maxLength={160} value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} placeholder="e.g. Osas Igbinovia" /></label>
            <label>Email address<input autoComplete="email" name="email" type="email" required maxLength={255} value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} placeholder="you@example.com" /></label>
            <label>Phone number <span className="optional">(optional)</span><input autoComplete="tel" name="phone" type="tel" maxLength={32} value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} placeholder="+234" /></label>
            {audience === 'provider' && <label>Business or professional name<input name="business_name" required minLength={2} maxLength={160} value={form.business_name} onChange={(event) => setForm({ ...form, business_name: event.target.value })} placeholder="What should customers call you?" /></label>}
            <label>What kind of service? <span className="optional">(optional)</span><select name="service_interest" value={form.service_interest} onChange={(event) => setForm({ ...form, service_interest: event.target.value })}><option value="">Choose one</option>{services.map((service) => <option value={service} key={service}>{service}</option>)}</select></label>
            {state === 'error' && <p className="form-error" role="alert">{error}</p>}
            <button className="button button-dark form-submit" disabled={state === 'sending'}>{state === 'sending' ? <><span className="button-spinner" />Adding you…</> : <>Join the waitlist <Arrow /></>}</button>
            <p className="privacy-note">By joining, you agree to our <a href="#privacy">Privacy Policy</a> and <a href="#terms">Terms</a>. We’ll only use your details for Neybor updates.</p>
          </form>}
        </div>
      </div></section>

      <section className="faq-section section-wrap" id="faqs"><div className="faq-intro"><div className="section-kicker">A FEW THINGS YOU MIGHT BE WONDERING</div><h2>Good questions.<br /><em>Straight answers.</em></h2><p>Still curious? Join the list and we’ll share more as we get closer.</p><a className="arrow-link" href="#waitlist">Get launch updates <Arrow /></a></div><div className="faq-list">
        {[
          ['What is Neybor?', 'Neybor is a location-based marketplace that helps customers discover nearby service professionals and helps local providers reach more customers. The mobile marketplace is being built separately from this pre-launch website.'],
          ['When will Neybor launch?', 'We’re preparing our first release for Benin City, Edo State. Join either waitlist and we’ll share launch updates when there is confirmed news.'],
          ['Is Neybor available outside Benin City?', 'Not yet. Benin City is our starting point. We’ll share expansion plans when we’re ready to serve new locations.'],
          ['How are professionals verified?', 'Provider verification is part of the planned marketplace experience. We’re developing identity review and account approval before providers can receive requests.'],
          ['Does joining cost anything?', 'No. Adding your email to the waitlist is free and does not create an account or start a paid service.'],
          ['How do I leave the waitlist?', 'Contact us with the email you used to register and ask us to remove your waitlist entry. We’ll publish a direct contact channel before launch.'],
        ].map(([question, answer]) => <details className="faq-item" key={question}><summary>{question}<span>+</span></summary><p>{answer}</p></details>)}
      </div></section>

      <section className="policy-section" id="privacy"><div className="section-wrap policy-grid"><div><div className="section-kicker">THE IMPORTANT DETAILS</div><h2>Privacy, with care.</h2><p className="policy-lead">Waitlist information is only for launch updates and early access.</p></div><div className="policy-copy"><p>When you register, we store the name, email, optional phone number, audience type, and service interest you submit. Provider registrations also include the professional or business name. The information is sent to Neybor’s backend and is not published as a profile.</p><p>We use these details to manage the waitlist, understand local launch interest, and contact you about Neybor. We do not sell waitlist information. Access is limited to authorized administrators. The pre-launch site does not request device location or notification permissions.</p><p>To request access, correction, or removal of your entry, use the waitlist contact channel when it is published. This notice will be expanded before public launch to include the final contact details, retention period, and applicable data-controller information.</p></div></div></section>
      <section className="policy-section terms-section" id="terms"><div className="section-wrap policy-grid"><div><div className="section-kicker">WAITLIST TERMS</div><h2>A simple promise.</h2><p className="policy-lead">The waitlist is a heads-up, not a booking.</p></div><div className="policy-copy"><p>Joining the Neybor waitlist is free and does not create a marketplace account, reserve a service, guarantee early access, or create a contract for paid work. Neybor is not currently accepting service requests or payments through this website.</p><p>Please provide accurate information and only submit contact details you are authorized to share. Do not submit sensitive identity documents or payment information through this site.</p><p>We may update these terms as the product develops. Material changes to how waitlist data is used will be reflected in the privacy notice and communicated where appropriate.</p></div></div></section>
      <section className="contact-section section-wrap" id="contact"><div><div className="section-kicker">STAY CLOSE</div><h2>We’re building this<br />with <em>our neighbours.</em></h2><p>Questions or feedback? Join the waitlist and we’ll share a contact channel before launch.</p><a className="button button-light" href="#waitlist">Stay in the loop <Arrow /></a></div><div className="contact-location"><span className="contact-pin">⌖</span><span>Starting in<br /><strong>Benin City, Edo State</strong></span></div></section>
    </main>

    <footer className="site-footer"><div className="footer-main"><div><Logo light /><p>A more thoughtful way to find<br />good work close to home.</p></div><div className="footer-links"><a href="#about">About</a><a href="#how-it-works">How it works</a><a href="#services">Services</a><a href="#faqs">FAQs</a><a href="#privacy">Privacy</a><a href="#terms">Terms</a><a href="#contact">Contact</a></div><a className="footer-top" href="#home" aria-label="Back to top">↑</a></div><div className="footer-bottom"><span>© {new Date().getFullYear()} Neybor. Made for the neighbourhood.</span><span>BENIN CITY, NIGERIA <i /> GOOD WORK, CLOSE BY.</span></div></footer>
  </div>;
}

export default App;
