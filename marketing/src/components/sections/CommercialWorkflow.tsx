import { Container } from '@/components/shared/Container';
import { StatutoryChain } from '@/components/sections/StatutoryChain';

export function CommercialWorkflow() {
  return (
    <section className="border-b border-border py-32 md:py-44">
      <Container>
        <div className="mx-auto max-w-[52ch] text-center">
          <div className="text-sm font-medium uppercase tracking-wide text-text-muted">Commercial Workflow</div>
          <h2 className="mt-3 text-3xl font-medium tracking-tight text-text-primary md:text-4xl">
            The statutory chain, calculated the moment a contract is confirmed.
          </h2>
          <p className="mt-5 text-text-secondary">
            Every payment application carries its due date, payment notice deadline,
            pay less notice deadline, and final date for payment, all computed from
            confirmed contract rules, not re-entered by hand for every application.
          </p>
        </div>

        <div className="mx-auto mt-20 max-w-xl">
          <StatutoryChain />
        </div>
      </Container>
    </section>
  );
}
